<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\Menu;
use App\Models\Pengaturan;
use App\Models\PenjualanHarian;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Perkiraan stok satu lokasi sebelum penjualan disimpan (penjualan memotong stok bahan lewat resep).
 * Tidak mengubah apa pun: hanya menghitung stok sekarang, pemakaian lama yang dikembalikan (bila penjualan diganti),
 * pemakaian baru menurut resep yang berlaku di tanggal penjualan, dan sisa stok sesudahnya beserta statusnya.
 */
class PerkiraanStokService
{
    private const AMBANG_BAWAAN = 20;

    /**
     * @param  list<array{tanggal: string, porsi: array<int|string, int|string|null>, ganti?: ?PenjualanHarian}>  $penjualan
     * @return Collection<int, array{bahan: BahanBaku, stok: float, kembali: float, pakai: float, sisa: float, minimum: float, status: string}>
     *         hanya bahan yang terpakai atau dikembalikan, urut dari yang paling gawat
     */
    public function penjualan(Cabang $lokasi, array $penjualan): Collection
    {
        $pakai = [];
        $kembali = [];

        foreach ($penjualan as $p) {
            foreach ($this->pemakaian($p['tanggal'], $p['porsi']) as $bahanId => $jumlah) {
                $pakai[$bahanId] = round(($pakai[$bahanId] ?? 0) + $jumlah, 3);
            }

            if ($p['ganti'] ?? null) {
                BarangKeluarDetail::query()
                    ->where('barang_keluar_id', $p['ganti']->barang_keluar_id)
                    ->get(['bahan_baku_id', 'qty'])
                    ->each(function (BarangKeluarDetail $d) use (&$kembali) {
                        $kembali[$d->bahan_baku_id] = round(($kembali[$d->bahan_baku_id] ?? 0) + (float) $d->qty, 3);
                    });
            }
        }

        $bahanIds = array_keys($pakai + $kembali);

        if ($bahanIds === []) {
            return collect();
        }

        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->whereIn('bahan_baku_id', $bahanIds)->pluck('saldo', 'bahan_baku_id');
        $minimum = BahanBakuMinimum::query()->where('cabang_id', $lokasi->id)->whereIn('bahan_baku_id', $bahanIds)->pluck('minimum', 'bahan_baku_id');
        $ambang = (float) Pengaturan::ambil('ambang_stok_menipis', self::AMBANG_BAWAAN);
        $urutan = array_flip(array_keys(PantauanStokService::TAMPILAN_STATUS));

        return BahanBaku::query()
            ->whereKey($bahanIds)
            ->with('satuanBeli:id,nama')
            ->get()
            ->map(function (BahanBaku $b) use ($saldo, $minimum, $pakai, $kembali, $ambang) {
                $stok = round((float) ($saldo[$b->id] ?? 0), 3);
                $sisa = round($stok + ($kembali[$b->id] ?? 0) - ($pakai[$b->id] ?? 0), 3);
                $min = (float) ($minimum[$b->id] ?? 0);

                return [
                    'bahan'   => $b,
                    'stok'    => $stok,
                    'kembali' => $kembali[$b->id] ?? 0.0,
                    'pakai'   => $pakai[$b->id] ?? 0.0,
                    'sisa'    => $sisa,
                    'minimum' => $min,
                    'status'  => PantauanStokService::status($sisa, $min, $ambang),
                ];
            })
            ->filter(fn (array $r) => $r['pakai'] != 0.0 || $r['kembali'] != 0.0)
            ->sortBy(fn (array $r) => [$urutan[$r['status']], $r['bahan']->nama])
            ->values();
    }

    /**
     * Pemakaian bahan (satuan dasar) dari porsi per menu, menurut resep yang berlaku di tanggal itu.
     *
     * @return array<int, float> [bahan_baku_id => jumlah]
     */
    private function pemakaian(string $tanggal, array $porsi): array
    {
        $porsi = collect($porsi)->map(fn ($p) => (int) $p)->filter(fn (int $p) => $p > 0);

        if ($porsi->isEmpty()) {
            return [];
        }

        $hasil = [];

        foreach (Menu::query()->aktif()->whereKey($porsi->keys()->all())->get() as $menu) {
            foreach ($menu->resepBerlaku($tanggal) as $resep) {
                $hasil[$resep->bahan_baku_id] = round(($hasil[$resep->bahan_baku_id] ?? 0) + $porsi[$menu->id] * (float) $resep->jumlah, 3);
            }
        }

        return $hasil;
    }
}
