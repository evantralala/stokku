<?php

namespace App\Services;

use App\Exceptions\LokasiSedangOpname;
use App\Exceptions\StokTidakCukup;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Opname;
use App\Models\StokMutasi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu untuk mengubah stok.
 *
 * Alur: catat() membuat mutasi berstatus menunggu (saldo belum berubah),
 * sahkan() menerapkan mutasi ke stok_saldo, batalkan() membatalkan mutasi yang masih menunggu.
 * Semua langkah berjalan di dalam DB::transaction(), dan baris stok_saldo dikunci dengan
 * lockForUpdate() sebelum dibaca supaya dua transaksi bersamaan tidak saling menimpa.
 * Lokasi yang sedang stock opname tidak bisa diubah stoknya kecuali oleh koreksi opname itu sendiri.
 */
class StokService
{
    /**
     * Catat mutasi berstatus menunggu.
     *
     * @param  iterable<array{cabang_id: int, bahan_baku_id: int, jumlah: float|int|string, dokumen_detail_id?: int|null}>  $baris
     *         jumlah dalam satuan dasar; positif = masuk, negatif = keluar.
     * @return Collection<int, StokMutasi>
     */
    public function catat(iterable $baris, string $jenis, string $dokumenTipe, int $dokumenId, ?int $userId): Collection
    {
        return DB::transaction(function () use ($baris, $jenis, $dokumenTipe, $dokumenId, $userId) {
            $hasil = collect();

            foreach ($baris as $b) {
                $hasil->push(StokMutasi::create([
                    'cabang_id'         => $b['cabang_id'],
                    'bahan_baku_id'     => $b['bahan_baku_id'],
                    'jumlah'            => $b['jumlah'],
                    'jenis'             => $jenis,
                    'status'            => StokMutasi::STATUS_MENUNGGU,
                    'dokumen_tipe'      => $dokumenTipe,
                    'dokumen_id'        => $dokumenId,
                    'dokumen_detail_id' => $b['dokumen_detail_id'] ?? null,
                    'dibuat_oleh'       => $userId,
                ]));
            }

            return $hasil;
        });
    }

    /**
     * Sahkan mutasi menunggu milik satu dokumen dan terapkan ke saldo.
     *
     * @param  list<int>|null  $detailIds  hanya baris detail ini; null = semua baris dokumen
     * @param  bool  $bolehMinus  izinkan saldo menjadi negatif (untuk pencatatan pemakaian yang harus tetap tercatat)
     * @return int jumlah mutasi yang disahkan
     *
     * @throws StokTidakCukup bila pengeluaran melebihi saldo dan $bolehMinus = false
     * @throws LokasiSedangOpname bila lokasi sedang stock opname
     */
    public function sahkan(string $dokumenTipe, int $dokumenId, ?array $detailIds = null, bool $bolehMinus = false): int
    {
        return DB::transaction(function () use ($dokumenTipe, $dokumenId, $detailIds, $bolehMinus) {
            $mutasi = StokMutasi::query()
                ->dokumen($dokumenTipe, $dokumenId)
                ->menunggu()
                ->when($detailIds !== null, fn ($q) => $q->whereIn('dokumen_detail_id', $detailIds))
                // Urutan kunci yang tetap mencegah deadlock antartransaksi.
                ->orderBy('cabang_id')
                ->orderBy('bahan_baku_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($dokumenTipe !== Opname::DOKUMEN_TIPE) {
                $this->pastikanTidakSedangOpname($mutasi->pluck('cabang_id')->unique()->all());
            }

            foreach ($mutasi as $m) {
                $this->terapkanKeSaldo($m, $bolehMinus);

                $m->status = StokMutasi::STATUS_SAH;
                $m->save();
            }

            return $mutasi->count();
        });
    }

    /**
     * Batalkan mutasi menunggu milik satu dokumen. Saldo tidak berubah.
     *
     * @param  list<int>|null  $detailIds
     */
    public function batalkan(string $dokumenTipe, int $dokumenId, ?array $detailIds = null): int
    {
        return DB::transaction(function () use ($dokumenTipe, $dokumenId, $detailIds) {
            $mutasi = StokMutasi::query()
                ->dokumen($dokumenTipe, $dokumenId)
                ->menunggu()
                ->when($detailIds !== null, fn ($q) => $q->whereIn('dokumen_detail_id', $detailIds))
                ->lockForUpdate()
                ->get();

            foreach ($mutasi as $m) {
                $m->status = StokMutasi::STATUS_BATAL;
                $m->save();
            }

            return $mutasi->count();
        });
    }

    /** Saldo saat ini (satuan dasar). Hanya untuk tampilan; jangan dipakai untuk memutuskan perubahan stok. */
    public function saldo(int $cabangId, int $bahanBakuId): float
    {
        return (float) DB::table('stok_saldo')
            ->where('cabang_id', $cabangId)
            ->where('bahan_baku_id', $bahanBakuId)
            ->value('saldo');
    }

    /** Saldo (satuan dasar) dengan kunci baris saldo, untuk memutuskan perubahan stok. Panggil di dalam DB::transaction(). */
    public function saldoTerkunci(int $cabangId, int $bahanBakuId): float
    {
        return round((float) DB::table('stok_saldo')
            ->where('cabang_id', $cabangId)
            ->where('bahan_baku_id', $bahanBakuId)
            ->lockForUpdate()
            ->value('saldo'), 3);
    }

    /**
     * Jumlah mutasi yang belum disahkan per bahan di satu lokasi (satuan dasar; positif = akan masuk).
     * Hanya untuk tampilan, mis. barang masuk yang menunggu persetujuan atau kiriman yang belum dihitung cabang.
     *
     * @return array<int, float>
     */
    public function menunggu(int $cabangId): array
    {
        return StokMutasi::query()
            ->menunggu()
            ->where('cabang_id', $cabangId)
            ->groupBy('bahan_baku_id')
            ->selectRaw('bahan_baku_id, SUM(jumlah) AS total')
            ->pluck('total', 'bahan_baku_id')
            ->map(fn ($total) => round((float) $total, 3))
            ->filter(fn ($total) => $total != 0.0)
            ->all();
    }

    /** @param  list<int>  $lokasiIds */
    private function pastikanTidakSedangOpname(array $lokasiIds): void
    {
        $terkunci = Cabang::query()
            ->whereKey($lokasiIds)
            ->where('opname_aktif', true)
            ->value('nama');

        if ($terkunci !== null) {
            throw new LokasiSedangOpname($terkunci);
        }
    }

    private function terapkanKeSaldo(StokMutasi $mutasi, bool $bolehMinus): void
    {
        $cabangId = $mutasi->cabang_id;
        $bahanBakuId = $mutasi->bahan_baku_id;
        $jumlah = (float) $mutasi->jumlah;

        DB::table('stok_saldo')->insertOrIgnore([
            'cabang_id'       => $cabangId,
            'bahan_baku_id'   => $bahanBakuId,
            'saldo'           => 0,
            'diperbarui_pada' => now(),
        ]);

        $saldo = (float) DB::table('stok_saldo')
            ->where('cabang_id', $cabangId)
            ->where('bahan_baku_id', $bahanBakuId)
            ->lockForUpdate()
            ->value('saldo');

        $baru = round($saldo + $jumlah, 3);

        if ($jumlah < 0 && $baru < 0 && ! $bolehMinus) {
            throw new StokTidakCukup(
                (string) BahanBaku::whereKey($bahanBakuId)->value('nama'),
                $saldo,
                abs($jumlah),
                $bahanBakuId
            );
        }

        DB::table('stok_saldo')
            ->where('cabang_id', $cabangId)
            ->where('bahan_baku_id', $bahanBakuId)
            ->update(['saldo' => $baru, 'diperbarui_pada' => now()]);
    }
}
