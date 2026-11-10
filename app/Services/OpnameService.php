<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Opname;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock opname: mulai (stok lokasi dikunci), hitung fisik tanpa melihat stok sistem, kirim ke Admin Utama.
 * Keputusan Admin Utama ada di PersetujuanService::putuskanOpname().
 */
class OpnameService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
    ) {
    }

    /**
     * Alasan opname belum bisa dimulai di lokasi ini (opname lain masih berjalan, atau ada dokumen yang menunggu
     * persetujuan), atau null bila bisa dimulai.
     */
    public function hambatanMulai(Cabang $lokasi): ?string
    {
        if ($lokasi->opname_aktif) {
            return 'Opname di lokasi ini masih berjalan. Lanjutkan opname tersebut.';
        }

        $dokumenMenunggu = StokMutasi::query()
            ->where('cabang_id', $lokasi->id)
            ->menunggu()
            ->distinct()
            ->count('dokumen_id');

        return $dokumenMenunggu > 0
            ? "Masih ada {$dokumenMenunggu} dokumen di lokasi ini yang menunggu persetujuan Admin Utama. "
                .'Selesaikan dulu supaya hasil opname tidak terhitung dua kali.'
            : null;
    }

    /**
     * Bahan yang ikut dihitung di lokasi ini: semua bahan aktif, ditambah bahan nonaktif yang masih punya stok.
     *
     * @return Collection<int, float> [bahan_baku_id => stok sistem (satuan dasar)]
     */
    public function bahanDihitung(Cabang $lokasi): Collection
    {
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->pluck('saldo', 'bahan_baku_id');

        return BahanBaku::query()
            ->where(fn ($q) => $q->where('aktif', true)->orWhereIn('id', $saldo->filter(fn ($s) => (float) $s != 0.0)->keys()))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => (float) ($saldo[$id] ?? 0)]);
    }

    public function mulai(User $petugas, Cabang $lokasi): Opname
    {
        return DB::transaction(function () use ($petugas, $lokasi) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();

            if ($hambatan = $this->hambatanMulai($lokasi)) {
                throw new DomainException($hambatan);
            }

            $sekarang = now();

            $opname = Opname::create([
                'nomor'      => NomorDokumen::berikutnya('opname', Opname::AWALAN_NOMOR, $lokasi, $sekarang),
                'cabang_id'  => $lokasi->id,
                'tanggal'    => $sekarang->toDateString(),
                'petugas_id' => $petugas->id,
                'status'     => Opname::STATUS_BERJALAN,
            ]);

            foreach ($this->bahanDihitung($lokasi) as $id => $sistem) {
                $opname->detail()->create([
                    'bahan_baku_id' => $id,
                    'qty_sistem'    => $sistem,
                ]);
            }

            $lokasi->update(['opname_aktif' => true]);

            return $opname;
        });
    }

    /**
     * Opname dari file Excel: dimulai, diisi hasil hitung, lalu langsung dikirim ke Admin Utama.
     * Bahan yang tidak ada di $hitung dianggap 0.
     *
     * @param  array<int, array{qty: float, catatan?: string|null}>  $hitung  [bahan_baku_id => ...], qty satuan beli
     */
    public function dariExcel(User $petugas, Cabang $lokasi, array $hitung): Opname
    {
        return DB::transaction(function () use ($petugas, $lokasi, $hitung) {
            $opname = $this->mulai($petugas, $lokasi);
            $detail = $opname->detail()->with('bahanBaku:id,nama')->get()->keyBy('bahan_baku_id');

            foreach (array_keys($hitung) as $bahanId) {
                if (! $detail->has($bahanId)) {
                    $nama = BahanBaku::query()->whereKey($bahanId)->value('nama') ?? 'Bahan';

                    throw new DomainException("{$nama} tidak ikut dihitung di opname ini karena sedang nonaktif dan stoknya kosong.");
                }
            }

            $isian = $detail->mapWithKeys(fn ($d) => [$d->id => [
                'qty'     => $hitung[$d->bahan_baku_id]['qty'] ?? 0,
                'catatan' => $hitung[$d->bahan_baku_id]['catatan'] ?? null,
            ]])->all();

            $this->simpanHitungan($opname, $isian, true);

            return $opname->refresh();
        });
    }

    /**
     * Simpan hasil hitung (boleh sebagian). Bila $kirim, semua bahan wajib sudah dihitung.
     *
     * @param  array<int|string, array{qty?: float|string|null, catatan?: string|null}>  $hitung  [opname_detail_id => ...], qty satuan beli
     * @return int jumlah bahan yang belum dihitung
     */
    public function simpanHitungan(Opname $opname, array $hitung, bool $kirim): int
    {
        return DB::transaction(function () use ($opname, $hitung, $kirim) {
            $opname = Opname::whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if (! $opname->sedangDihitung()) {
                throw new DomainException('Opname ini sudah dikirim ke Admin Utama, jadi hasil hitung tidak bisa diubah.');
            }

            $detail = $opname->detail()->with('bahanBaku:id,isi_per_satuan_beli')->get();

            foreach ($detail as $d) {
                if (! array_key_exists($d->id, $hitung)) {
                    continue;
                }

                $qty = $hitung[$d->id]['qty'] ?? null;

                $d->update([
                    'qty_fisik' => $qty === null ? null : $d->bahanBaku->keSatuanDasar($qty),
                    'catatan'   => $hitung[$d->id]['catatan'] ?? null,
                ]);
            }

            $belum = $opname->detail()->whereNull('qty_fisik')->count();

            if ($kirim) {
                if ($belum > 0) {
                    throw new DomainException("Masih ada {$belum} bahan yang belum dihitung. Isi 0 untuk bahan yang habis.");
                }

                $opname->update(['status' => Opname::STATUS_DIKIRIM]);

                $this->notifikasi->keAdminUtama(
                    'Hasil stock opname '.$opname->cabang->nama,
                    $opname->detail()->count().' bahan dihitung · '.$opname->nomor.' · stok lokasi dikunci sampai diputuskan',
                    route('admin-utama.persetujuan.opname', $opname, false)
                );
            }

            return $belum;
        });
    }

    public function batalkan(Opname $opname): Opname
    {
        return DB::transaction(function () use ($opname) {
            $opname = Opname::whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if (! $opname->sedangDihitung()) {
                throw new DomainException('Opname yang sudah dikirim ke Admin Utama tidak bisa dibatalkan.');
            }

            $opname->update(['status' => Opname::STATUS_BATAL]);
            Cabang::whereKey($opname->cabang_id)->update(['opname_aktif' => false]);

            return $opname;
        });
    }
}
