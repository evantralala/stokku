<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\PenyesuaianStok;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\Angka;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ubah stok langsung: stok satu bahan disamakan dengan jumlah sebenarnya, tanpa persetujuan.
 * Selisihnya dicatat sebagai mutasi penyesuaian yang langsung sah, dengan dokumen bernomor (stok lama, stok baru, selisih).
 * Admin Utama diberi tahu bila yang mengubah Admin Pusat atau cabang.
 */
class PenyesuaianStokService
{
    private const TOLERANSI = 0.0005;

    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
        private LogPerubahanService $log,
    ) {
    }

    /**
     * $stokBaruBeli dalam satuan beli; $stokLamaDasar = stok (satuan dasar) yang dilihat pengguna saat membuka form.
     * Ditolak bila stok sudah berubah sejak form dibuka, atau jumlahnya sama dengan stok sekarang.
     * $beriTahu = false untuk pembaruan massal yang mengirim satu pemberitahuan sendiri.
     */
    public function sesuaikan(
        User $oleh,
        Cabang $lokasi,
        BahanBaku $bahan,
        float $stokBaruBeli,
        float $stokLamaDasar,
        ?string $keterangan,
        bool $beriTahu = true,
    ): PenyesuaianStok {
        if (! $bahan->aktif) {
            throw new DomainException("Bahan {$bahan->nama} sedang nonaktif.");
        }

        $bahan->loadMissing('satuanBeli:id,nama');

        $dokumen = DB::transaction(function () use ($oleh, $lokasi, $bahan, $stokBaruBeli, $stokLamaDasar, $keterangan) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();
            $lama = $this->stok->saldoTerkunci($lokasi->id, $bahan->id);

            if (abs($lama - $stokLamaDasar) > self::TOLERANSI) {
                throw new DomainException("Stok {$bahan->nama} baru saja berubah menjadi {$this->teksJumlah($bahan, $lama)}. Buka lagi Ubah stok lalu isi ulang.");
            }

            $baru = $bahan->keSatuanDasar($stokBaruBeli);
            $selisih = round($baru - $lama, 3);

            if (abs($selisih) <= self::TOLERANSI) {
                throw new DomainException("Stok {$bahan->nama} sudah {$this->teksJumlah($bahan, $lama)}. Tidak ada yang diubah.");
            }

            $dokumen = PenyesuaianStok::create([
                'nomor'               => NomorDokumen::berikutnya('penyesuaian_stok', PenyesuaianStok::AWALAN_NOMOR, $lokasi, now()),
                'cabang_id'           => $lokasi->id,
                'bahan_baku_id'       => $bahan->id,
                'stok_lama'           => $lama,
                'stok_baru'           => $baru,
                'selisih'             => $selisih,
                'keterangan'          => $keterangan,
                'dibuat_oleh'         => $oleh->id,
            ]);

            $this->stok->catat([[
                'cabang_id'           => $lokasi->id,
                'bahan_baku_id'       => $bahan->id,
                'jumlah'              => $selisih,
            ]], StokMutasi::JENIS_PENYESUAIAN, PenyesuaianStok::DOKUMEN_TIPE, $dokumen->id, $oleh->id);
            $this->stok->sahkan(PenyesuaianStok::DOKUMEN_TIPE, $dokumen->id);

            $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'stok.ubah',
                "Ubah stok {$bahan->nama} di {$lokasi->nama}: {$this->teksJumlah($bahan, $lama)} menjadi {$this->teksJumlah($bahan, $baru)} ({$dokumen->nomor}).",
                [], $dokumen);

            return $dokumen;
        });

        if ($beriTahu && ! $oleh->isAdminUtama()) {
            $this->notifikasi->keAdminUtama(
                'Stok diubah langsung: '.$lokasi->nama,
                "{$bahan->nama} {$this->teksJumlah($bahan, (float) $dokumen->stok_lama)} → {$this->teksJumlah($bahan, (float) $dokumen->stok_baru)} · {$dokumen->nomor} · {$oleh->nama}",
                route('admin-utama.stok.riwayat', ['bahanBaku' => $bahan->id, 'lokasi' => $lokasi->id], false)
            );
        }

        return $dokumen;
    }

    /**
     * Kosongkan stok (Admin Utama): stok bahan-bahan terpilih di satu lokasi menjadi 0, mis. barang dibuang atau
     * bahan tidak dipakai lagi (bahan nonaktif ikut bisa dikosongkan). Tiap bahan menjadi dokumen penyesuaian yang
     * langsung sah, sehingga kartu stok dan riwayat tetap utuh. Bahan yang stoknya sudah 0 dilewati.
     *
     * @param  iterable<int>  $bahanId
     * @return int jumlah bahan yang dikosongkan
     */
    public function kosongkan(User $oleh, Cabang $lokasi, iterable $bahanId, string $alasan): int
    {
        return DB::transaction(function () use ($oleh, $lokasi, $bahanId, $alasan) {
            $lokasi = Cabang::whereKey($lokasi->id)->lockForUpdate()->firstOrFail();

            if ($lokasi->opname_aktif) {
                throw new DomainException("{$lokasi->nama} sedang stock opname. Selesaikan opname dulu.");
            }

            $bahan = BahanBaku::query()->with('satuanBeli:id,nama')->whereKey(collect($bahanId)->all())->orderBy('nama')->get();
            $dikosongkan = [];

            foreach ($bahan as $b) {
                $lama = $this->stok->saldoTerkunci($lokasi->id, $b->id);

                if (abs($lama) <= self::TOLERANSI) {
                    continue;
                }

                $dokumen = PenyesuaianStok::create([
                    'nomor'         => NomorDokumen::berikutnya('penyesuaian_stok', PenyesuaianStok::AWALAN_NOMOR, $lokasi, now()),
                    'cabang_id'     => $lokasi->id,
                    'bahan_baku_id' => $b->id,
                    'stok_lama'     => $lama,
                    'stok_baru'     => 0,
                    'selisih'       => round(-$lama, 3),
                    'keterangan'    => 'Dikosongkan: '.$alasan,
                    'dibuat_oleh'   => $oleh->id,
                ]);

                $this->stok->catat([[
                    'cabang_id'     => $lokasi->id,
                    'bahan_baku_id' => $b->id,
                    'jumlah'        => round(-$lama, 3),
                ]], StokMutasi::JENIS_PENYESUAIAN, PenyesuaianStok::DOKUMEN_TIPE, $dokumen->id, $oleh->id);
                $this->stok->sahkan(PenyesuaianStok::DOKUMEN_TIPE, $dokumen->id, bolehMinus: true);

                $dikosongkan[] = ['label' => $b->nama, 'lama' => $this->teksJumlah($b, $lama), 'baru' => $this->teksJumlah($b, 0)];
            }

            if ($dikosongkan === []) {
                throw new DomainException('Stok bahan yang dipilih sudah 0. Tidak ada yang dikosongkan.');
            }

            $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'stok.kosongkan',
                'Kosongkan stok '.count($dikosongkan).' bahan di '.$lokasi->nama.': '.$alasan.'.', $dikosongkan);

            return count($dikosongkan);
        });
    }

    public function teksJumlah(BahanBaku $bahan, float $jumlahDasar): string
    {
        $bahan->loadMissing('satuanBeli:id,nama');

        return Angka::tampil($bahan->keSatuanBeli($jumlahDasar)).' '.$bahan->satuanBeli->nama;
    }
}
