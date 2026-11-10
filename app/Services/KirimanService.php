<?php

namespace App\Services;

use App\Exceptions\StokTidakCukup;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Kiriman barang antar lokasi.
 *
 * kirim(): Gudang Pusat mengirim barang permintaan cabang; stok pusat langsung berkurang (mutasi kirim_keluar disahkan)
 *          dan qty_dikirim permintaan bertambah.
 * kirimLangsung(): cabang mengirim barang sendiri ke cabang lain atau retur ke Gudang Pusat, tanpa permintaan dan tanpa
 *          persetujuan; stok cabang pengirim langsung berkurang. Admin Utama diberi tahu.
 * terima(): lokasi tujuan mencocokkan barang dengan data kiriman (sesuai semua, atau isi per barang); stok tujuan
 *          bertambah sebanyak yang diterima.
 * Barang permintaan dicocokkan lewat bahan_baku_id karena satu permintaan tidak memuat bahan yang sama dua kali.
 */
class KirimanService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
    ) {
    }

    /**
     * Kirim barang yang disetujui Admin Utama dari satu permintaan, sebanyak yang ada di gudang.
     * Jumlah tidak diketik ulang: tiap barang dikirim sisa yang disetujui, atau sebanyak stok gudang bila kurang;
     * kekurangannya tetap tercatat sebagai sisa dan muncul lagi di Siap Dikirim.
     *
     * @param  array{catatan_pusat?: string|null}  $info
     *
     * @throws DomainException bila stok gudang kosong untuk semua barang
     */
    public function kirimYangAda(Permintaan $permintaan, Cabang $pusat, User $pengirim, array $info = []): Kiriman
    {
        return $this->kirimPermintaan($permintaan, $pusat, $pengirim, $info, function ($detail) use ($pusat) {
            $jumlah = $detail->mapWithKeys(fn (PermintaanDetail $d) => [
                $d->id => round(min((float) $d->utang_kiriman, max(0, $this->stok->saldo($pusat->id, $d->bahan_baku_id))), 3),
            ])->filter(fn (float $qty) => $qty > 0);

            if ($jumlah->isEmpty()) {
                throw new DomainException("Stok {$pusat->nama} kosong untuk semua barang permintaan ini. Isi stok dulu lewat Barang Masuk.");
            }

            return $jumlah->all();
        });
    }

    /**
     * Kirim beberapa permintaan sekaligus (kirimYangAda); tiap permintaan menjadi satu kiriman tersendiri.
     * Barang yang stoknya kurang dikirim sebagian; permintaan yang stoknya kosong semua dilewati tanpa membatalkan yang lain.
     *
     * @param  iterable<Permintaan>  $daftar
     * @return array{kiriman: list<Kiriman>, gagal: array<string, string>} gagal: [nomor permintaan => alasan]
     */
    public function kirimBanyak(iterable $daftar, Cabang $pusat, User $pengirim): array
    {
        $hasil = ['kiriman' => [], 'gagal' => []];

        foreach ($daftar as $permintaan) {
            try {
                $hasil['kiriman'][] = $this->kirimYangAda($permintaan, $pusat, $pengirim);
            } catch (StokTidakCukup $e) {
                $hasil['gagal'][$permintaan->nomor] = $e->pesanDi($pusat->nama);
            } catch (DomainException $e) {
                $hasil['gagal'][$permintaan->nomor] = $e->getMessage();
            }
        }

        return $hasil;
    }

    /**
     * Terima beberapa kiriman sekaligus dengan pernyataan "sesuai semua". Kiriman yang gagal dilewati.
     *
     * @param  iterable<Kiriman>  $daftar
     * @return array{diterima: list<string>, gagal: array<string, string>} nomor kiriman
     */
    public function terimaSesuaiBanyak(iterable $daftar, User $penerima): array
    {
        $hasil = ['diterima' => [], 'gagal' => []];

        foreach ($daftar as $kiriman) {
            try {
                $this->terimaSesuai($kiriman, $penerima);
                $hasil['diterima'][] = $kiriman->nomor;
            } catch (DomainException $e) {
                $hasil['gagal'][$kiriman->nomor] = $e->getMessage();
            }
        }

        return $hasil;
    }

    /**
     * @param  callable(\Illuminate\Support\Collection<int, PermintaanDetail>): array<int, float>  $jumlahDasar
     *         menerima barang yang masih punya sisa kiriman, mengembalikan [permintaan_detail_id => qty satuan dasar]
     */
    private function kirimPermintaan(Permintaan $permintaan, Cabang $pusat, User $pengirim, array $info, callable $jumlahDasar): Kiriman
    {
        return DB::transaction(function () use ($permintaan, $pusat, $pengirim, $info, $jumlahDasar) {
            $permintaan = Permintaan::whereKey($permintaan->id)->lockForUpdate()->firstOrFail();

            if ($permintaan->status !== Permintaan::STATUS_MENUNGGU) {
                throw new DomainException('Permintaan ini sudah selesai atau dibatalkan.');
            }

            $detail = $permintaan->detail()->masihUtang()->lockForUpdate()->get()->keyBy('id');
            $baris = [];

            foreach ($jumlahDasar($detail) as $detailId => $qty) {
                $d = $detail->get((int) $detailId);

                if (! $d || $qty <= 0) {
                    continue;
                }

                if ($qty > $d->utang_kiriman + 0.0005) {
                    throw new DomainException('Jumlah kiriman melebihi sisa yang disetujui Admin Utama.');
                }

                $baris[] = [$d, round($qty, 3)];
            }

            if ($baris === []) {
                throw new DomainException('Isi jumlah kiriman minimal untuk satu barang.');
            }

            $sekarang = now();

            $kiriman = Kiriman::create([
                'nomor'          => NomorDokumen::berikutnya('kiriman', Kiriman::AWALAN_NOMOR, $pusat, $sekarang),
                'dari_cabang_id' => $pusat->id,
                'ke_cabang_id'   => $permintaan->cabang_id,
                'permintaan_id'  => $permintaan->id,
                'dikirim_oleh'   => $pengirim->id,
                'dikirim_pada'   => $sekarang,
                'catatan_pusat'  => $info['catatan_pusat'] ?? null,
                'status'         => Kiriman::STATUS_DIKIRIM,
            ]);

            $mutasi = [];

            foreach ($baris as [$d, $qty]) {
                $kirimanDetail = $kiriman->detail()->create([
                    'bahan_baku_id' => $d->bahan_baku_id,
                    'qty_kirim'     => $qty,
                ]);

                $mutasi[] = [
                    'cabang_id'         => $pusat->id,
                    'bahan_baku_id'     => $d->bahan_baku_id,
                    'jumlah'            => -$qty,
                    'dokumen_detail_id' => $kirimanDetail->id,
                ];

                $d->update(['qty_dikirim' => round((float) $d->qty_dikirim + $qty, 3)]);
            }

            $this->stok->catat($mutasi, StokMutasi::JENIS_KIRIM_KELUAR, Kiriman::DOKUMEN_TIPE, $kiriman->id, $pengirim->id);
            $this->stok->sahkan(Kiriman::DOKUMEN_TIPE, $kiriman->id);

            $this->tutupPermintaanBilaTuntas($permintaan);

            if (! $permintaan->detail()->masihUtang()->exists()) {
                $this->notifikasi->selesaikan(route('pusat.kiriman.buat', $permintaan, false));
            }

            $this->beritahuTujuan($kiriman, $pusat, count($baris));

            return $kiriman;
        });
    }

    /**
     * Kiriman dari cabang ke cabang lain atau ke Gudang Pusat (retur), tanpa permintaan dan tanpa persetujuan.
     *
     * @param  array<int|string, float|string>  $jumlah  [bahan_baku_id => qty dalam satuan beli]
     */
    public function kirimLangsung(Cabang $dari, Cabang $ke, User $pengirim, array $jumlah, ?string $catatan = null): Kiriman
    {
        if ($dari->id === $ke->id) {
            throw new DomainException('Lokasi tujuan tidak boleh sama dengan lokasi pengirim.');
        }

        if (! Cabang::query()->whereKey($ke->id)->value('aktif')) {
            throw new DomainException("{$ke->nama} sedang nonaktif.");
        }

        $kiriman = DB::transaction(function () use ($dari, $ke, $pengirim, $jumlah, $catatan) {
            $dari = Cabang::whereKey($dari->id)->lockForUpdate()->firstOrFail();
            $bahan = BahanBaku::query()->aktif()->whereKey(array_keys($jumlah))->get()->keyBy('id');
            $baris = [];

            foreach ($jumlah as $bahanId => $qtyBeli) {
                $b = $bahan->get((int) $bahanId);

                if (! $b) {
                    throw new DomainException('Ada bahan yang sudah tidak aktif. Muat ulang form lalu pilih lagi.');
                }

                if ((float) $qtyBeli > 0) {
                    $baris[] = [$b, $b->keSatuanDasar($qtyBeli)];
                }
            }

            if ($baris === []) {
                throw new DomainException('Isi jumlah kiriman minimal untuk satu barang.');
            }

            $sekarang = now();

            $kiriman = Kiriman::create([
                'nomor'          => NomorDokumen::berikutnya('kiriman', Kiriman::AWALAN_NOMOR, $dari, $sekarang),
                'dari_cabang_id' => $dari->id,
                'ke_cabang_id'   => $ke->id,
                'permintaan_id'  => null,
                'dikirim_oleh'   => $pengirim->id,
                'dikirim_pada'   => $sekarang,
                'catatan_pusat'  => $catatan,
                'status'         => Kiriman::STATUS_DIKIRIM,
            ]);

            $mutasi = [];

            foreach ($baris as [$b, $qty]) {
                $kirimanDetail = $kiriman->detail()->create(['bahan_baku_id' => $b->id, 'qty_kirim' => $qty]);

                $mutasi[] = [
                    'cabang_id'         => $dari->id,
                    'bahan_baku_id'     => $b->id,
                    'jumlah'            => -$qty,
                    'dokumen_detail_id' => $kirimanDetail->id,
                ];
            }

            $this->stok->catat($mutasi, StokMutasi::JENIS_KIRIM_KELUAR, Kiriman::DOKUMEN_TIPE, $kiriman->id, $pengirim->id);
            $this->stok->sahkan(Kiriman::DOKUMEN_TIPE, $kiriman->id);

            $this->beritahuTujuan($kiriman, $dari, count($baris));

            return $kiriman;
        });

        $this->notifikasi->keAdminUtama(
            'Kiriman dari '.$dari->nama.' ke '.$ke->nama,
            $kiriman->detail()->count().' barang · '.$kiriman->nomor.' · '.$pengirim->nama,
            self::ruteInfoAdminUtama($kiriman)
        );

        return $kiriman;
    }

    /** Halaman kiriman untuk lokasi pengirim: kiriman Gudang Pusat atau kirim barang cabang. */
    public static function ruteLihatPengirim(Kiriman $kiriman): string
    {
        $kiriman->loadMissing('dariCabang:id,jenis');

        return route($kiriman->dariCabang->isPusat() ? 'pusat.kiriman.lihat' : 'cabang.kirim-barang.lihat', $kiriman, false);
    }

    /**
     * Tautan notifikasi "kiriman dari ... ke ..." untuk Admin Utama: kartu stok transaksi lokasi pengirim.
     * Penanda #kiriman-{id} membuat tautan unik per kiriman, supaya notifikasinya bisa diselesaikan saat barang diterima.
     */
    public static function ruteInfoAdminUtama(Kiriman $kiriman): string
    {
        return route('admin-utama.stok.mutasi', ['lokasi' => $kiriman->dari_cabang_id, 'tampil' => 'transaksi'], false).'#kiriman-'.$kiriman->id;
    }

    /** Halaman terima barang untuk lokasi tujuan. */
    public static function ruteTerima(Kiriman $kiriman): string
    {
        $kiriman->loadMissing('keCabang:id,jenis');

        return route($kiriman->keCabang->isPusat() ? 'pusat.terima-barang.lihat' : 'cabang.terima-barang.lihat', $kiriman, false);
    }

    private function beritahuTujuan(Kiriman $kiriman, Cabang $dari, int $jumlahBarang): void
    {
        $this->notifikasi->keLokasi(
            $kiriman->ke_cabang_id,
            'Kiriman '.$kiriman->nomor.' dalam perjalanan',
            $jumlahBarang.' barang dari '.$dari->nama.'. Hitung barang saat tiba.',
            self::ruteTerima($kiriman)
        );
    }

    /**
     * Checklist terima di cabang. Semua barang kiriman wajib diisi.
     *
     * @param  array<int|string, array{qty: float|string, kondisi: string, catatan?: string|null}>  $baris
     *         [kiriman_detail_id => ...], qty dalam satuan beli
     * @return bool true bila semua barang diterima sesuai (dalam toleransi)
     */
    public function terima(Kiriman $kiriman, User $penerima, array $baris): bool
    {
        return $this->simpanTerima($kiriman, $penerima, $baris);
    }

    /** Cabang menyatakan semua barang sesuai: diterima sebanyak yang dikirim, kondisi baik. */
    public function terimaSesuai(Kiriman $kiriman, User $penerima): bool
    {
        $baris = $kiriman->detail()
            ->get(['id', 'qty_kirim'])
            ->mapWithKeys(fn (KirimanDetail $d) => [$d->id => ['qty_dasar' => (float) $d->qty_kirim, 'kondisi' => KirimanDetail::KONDISI_BAIK]])
            ->all();

        return $this->simpanTerima($kiriman, $penerima, $baris);
    }

    /** @param  array<int|string, array{qty?: float|string, qty_dasar?: float, kondisi: string, catatan?: string|null}>  $baris */
    private function simpanTerima(Kiriman $kiriman, User $penerima, array $baris): bool
    {
        return DB::transaction(function () use ($kiriman, $penerima, $baris) {
            $kiriman = Kiriman::whereKey($kiriman->id)->lockForUpdate()->firstOrFail();

            if (! $kiriman->menungguDiterima()) {
                throw new DomainException('Kiriman ini sudah diterima sebelumnya.');
            }

            $detail = $kiriman->detail()->with('bahanBaku:id,nama,isi_per_satuan_beli,toleransi_persen')->lockForUpdate()->get();

            if ($detail->pluck('id')->diff(array_keys($baris))->isNotEmpty()) {
                throw new DomainException('Isi jumlah diterima untuk semua barang dalam kiriman.');
            }

            $sekarang = now();
            $mutasi = [];
            $sesuai = true;

            foreach ($detail as $d) {
                $isian = $baris[$d->id];
                $qty = match (true) {
                    $isian['kondisi'] === KirimanDetail::KONDISI_TIDAK_ADA => 0.0,
                    isset($isian['qty_dasar'])                             => (float) $isian['qty_dasar'],
                    default                                                => $d->bahanBaku->keSatuanDasar($isian['qty']),
                };

                if ($qty > (float) $d->qty_kirim + 0.0005) {
                    throw new DomainException("Jumlah diterima {$d->bahanBaku->nama} tidak boleh lebih dari yang dikirim. Bila barang yang datang memang lebih, terima sesuai surat jalan lalu kabari Admin Utama.");
                }

                $d->update([
                    'qty_terima'       => $qty,
                    'kondisi'          => $isian['kondisi'],
                    'catatan_penerima' => $isian['catatan'] ?? null,
                    'dichecklist_oleh' => $penerima->id,
                    'dichecklist_pada' => $sekarang,
                ]);

                if ($d->selisihDiLuarToleransi((float) $d->bahanBaku->toleransi_persen)) {
                    $sesuai = false;

                    if (blank($isian['catatan'] ?? null)) {
                        throw new DomainException("Tulis penjelasan untuk {$d->bahanBaku->nama}: jumlah diterima berbeda dari yang dikirim.");
                    }
                }

                if ($qty > 0) {
                    $mutasi[] = [
                        'cabang_id'         => $kiriman->ke_cabang_id,
                        'bahan_baku_id'     => $d->bahan_baku_id,
                        'jumlah'            => $qty,
                        'dokumen_detail_id' => $d->id,
                    ];
                }
            }

            if ($mutasi !== []) {
                $this->stok->catat($mutasi, StokMutasi::JENIS_KIRIM_MASUK, Kiriman::DOKUMEN_TIPE, $kiriman->id, $penerima->id);
                $this->stok->sahkan(Kiriman::DOKUMEN_TIPE, $kiriman->id);
            }

            $kiriman->update([
                'sampai_pada' => $sekarang,
                'status'      => $sesuai ? Kiriman::STATUS_SELESAI : Kiriman::STATUS_DICHECKLIST,
            ]);

            $tujuan = $kiriman->keCabang->nama;

            $this->notifikasi->selesaikan(self::ruteTerima($kiriman), self::ruteInfoAdminUtama($kiriman));

            $this->notifikasi->keLokasi(
                $kiriman->dari_cabang_id,
                ($sesuai ? 'Kiriman diterima sesuai: ' : 'Kiriman diterima dengan selisih: ').$kiriman->nomor,
                $tujuan.' selesai menghitung barang.',
                self::ruteLihatPengirim($kiriman)
            );

            if (! $sesuai) {
                $this->notifikasi->keAdminUtama(
                    'Selisih kiriman di '.$tujuan,
                    'Jumlah diterima '.$kiriman->nomor.' berbeda dari yang dikirim, melebihi toleransi bahan.',
                    route('admin-utama.stok', ['lokasi' => $kiriman->ke_cabang_id], false)
                );
            }

            return $sesuai;
        });
    }

    /** Permintaan selesai bila tidak ada barang menunggu keputusan dan semua yang disetujui sudah terkirim penuh. */
    private function tutupPermintaanBilaTuntas(Permintaan $permintaan): void
    {
        $masihTerbuka = $permintaan->detail()
            ->where(fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU)->orWhere(fn ($q) => $q->masihUtang()))
            ->exists();

        if (! $masihTerbuka) {
            $permintaan->update(['status' => Permintaan::STATUS_SELESAI]);
        }
    }
}
