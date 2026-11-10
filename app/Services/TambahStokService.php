<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\StokMutasi;
use App\Models\TambahStok;
use App\Models\User;
use App\Support\Angka;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tambah stok langsung: semua peran boleh, tanpa persetujuan dan tanpa alasan wajib.
 * Setiap tambahan menjadi dokumen bernomor dengan mutasi yang langsung sah, jadi selalu tampil di riwayat.
 * Admin Utama diberi tahu bila yang menambah Admin Pusat atau cabang.
 */
class TambahStokService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
        private LogPerubahanService $log,
    ) {
    }

    /**
     * $jumlahBeli dalam satuan beli.
     * $beriTahu false: pemanggil mengirim sendiri pemberitahuan ringkas (mis. isi stok banyak bahan).
     */
    public function tambah(
        User $oleh, Cabang $lokasi, BahanBaku $bahan, float $jumlahBeli, ?string $keterangan, bool $beriTahu = true,
    ): TambahStok
    {
        if (! $bahan->aktif) {
            throw new DomainException("Bahan {$bahan->nama} sedang nonaktif.");
        }

        $dokumen = DB::transaction(function () use ($oleh, $lokasi, $bahan, $jumlahBeli, $keterangan) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();
            $jumlah = $bahan->keSatuanDasar($jumlahBeli);

            $dokumen = TambahStok::create([
                'nomor'               => NomorDokumen::berikutnya('tambah_stok', TambahStok::AWALAN_NOMOR, $lokasi, now()),
                'cabang_id'           => $lokasi->id,
                'bahan_baku_id'       => $bahan->id,
                'jumlah'              => $jumlah,
                'keterangan'          => $keterangan,
                'dibuat_oleh'         => $oleh->id,
            ]);

            $this->stok->catat([[
                'cabang_id'           => $lokasi->id,
                'bahan_baku_id'       => $bahan->id,
                'jumlah'              => $jumlah,
            ]], StokMutasi::JENIS_TAMBAH_LANGSUNG, TambahStok::DOKUMEN_TIPE, $dokumen->id, $oleh->id);
            $this->stok->sahkan(TambahStok::DOKUMEN_TIPE, $dokumen->id);

            $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'stok.tambah',
                "Tambah stok {$bahan->nama} di {$lokasi->nama}: ".$this->teksJumlah($bahan, $jumlah)." ({$dokumen->nomor}).", [], $dokumen);

            return $dokumen;
        });

        if ($beriTahu && ! $oleh->isAdminUtama()) {
            $this->notifikasi->keAdminUtama(
                'Stok ditambah langsung: '.$lokasi->nama,
                "{$bahan->nama} +".$this->teksJumlah($bahan, (float) $dokumen->jumlah)." · {$dokumen->nomor} · {$oleh->nama}",
                route('admin-utama.stok.riwayat', ['bahanBaku' => $bahan->id, 'lokasi' => $lokasi->id], false)
            );
        }

        return $dokumen;
    }

    private function teksJumlah(BahanBaku $bahan, float $jumlahDasar): string
    {
        $bahan->loadMissing('satuanBeli:id,nama');

        return Angka::tampil($bahan->keSatuanBeli($jumlahDasar)).' '.$bahan->satuanBeli->nama;
    }
}
