<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Opname;
use App\Models\StokMutasi;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Persetujuan;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan Admin Utama atas dokumen yang menunggu persetujuan.
 * Setiap keputusan dicatat di tabel persetujuan; perubahan stok hanya lewat StokService.
 */
class PersetujuanService
{
    public const AKSI_SETUJUI = 'setujui';
    public const AKSI_TOLAK = 'tolak';
    public const AKSI_NANTI = 'nanti';
    public const AKSI_REVISI = 'revisi';

    public const CATATAN_LEWAT_BATAS = 'Melewati batas waktu persetujuan.';

    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
    ) {
    }

    /**
     * Putuskan barang-barang dalam satu permintaan. Barang yang tidak disebut (atau "nanti") tetap menunggu.
     *
     * @param  array<int|string, array{aksi: string, qty?: float|string|null, catatan?: string|null}>  $keputusan
     *         [permintaan_detail_id => ...], qty dalam satuan beli
     * @return array{disetujui: int, ditolak: int}
     */
    public function putuskanPermintaan(Permintaan $permintaan, User $adminUtama, array $keputusan): array
    {
        $hasil = DB::transaction(function () use ($permintaan, $adminUtama, $keputusan) {
            $permintaan = Permintaan::whereKey($permintaan->id)->lockForUpdate()->firstOrFail();

            if ($permintaan->status !== Permintaan::STATUS_MENUNGGU) {
                throw new DomainException('Permintaan ini sudah selesai atau dibatalkan.');
            }

            if ($permintaan->lewatBatas()) {
                return null;
            }

            $detail = $permintaan->detail()
                ->where('status', PermintaanDetail::STATUS_MENUNGGU)
                ->whereKey(array_keys($keputusan))
                ->lockForUpdate()
                ->get();

            $isi = BahanBaku::whereKey($detail->pluck('bahan_baku_id'))->pluck('isi_per_satuan_beli', 'id');
            $hitung = ['disetujui' => 0, 'ditolak' => 0];

            foreach ($detail as $d) {
                $k = $keputusan[$d->id];
                $catatan = $k['catatan'] ?? null;

                if ($k['aksi'] === self::AKSI_SETUJUI) {
                    $qty = round((float) $k['qty'] * (float) $isi[$d->bahan_baku_id], 3);

                    $d->update(['status' => PermintaanDetail::STATUS_DISETUJUI, 'qty_disetujui' => $qty]);
                    $this->catat(Permintaan::DOKUMEN_TIPE, $permintaan->id, $d->id, Persetujuan::KEPUTUSAN_DISETUJUI, $adminUtama, $catatan, $qty);
                    $hitung['disetujui']++;
                } elseif ($k['aksi'] === self::AKSI_TOLAK) {
                    $d->update(['status' => PermintaanDetail::STATUS_DITOLAK, 'qty_disetujui' => 0]);
                    $this->catat(Permintaan::DOKUMEN_TIPE, $permintaan->id, $d->id, Persetujuan::KEPUTUSAN_DITOLAK, $adminUtama, $catatan);
                    $hitung['ditolak']++;
                }
            }

            $this->tutupBilaTakAdaYangDikirim($permintaan);
            $this->selesaikanNotifikasiPermintaan($permintaan);
            $this->beritahuKeputusanPermintaan($permintaan, $hitung['disetujui'], $hitung['ditolak']);

            return $hitung;
        });

        if ($hasil === null) {
            $this->tolakOtomatis();

            throw new DomainException('Batas waktu persetujuan sudah lewat, jadi barang yang belum diputuskan otomatis ditolak.');
        }

        return $hasil;
    }

    /**
     * Putuskan satu barang masuk.
     * Disetujui: stok gudang bertambah. Revisi: dikembalikan ke pencatat untuk diperbaiki. Tolak: dibatalkan.
     */
    public function putuskanBarangMasuk(BarangMasuk $dokumen, User $adminUtama, string $aksi, ?string $catatan): BarangMasuk
    {
        return $this->putuskanDokumenStok(BarangMasuk::class, $dokumen->id, $adminUtama, $aksi, $catatan);
    }

    /**
     * Putuskan satu barang keluar.
     * Disetujui: stok berkurang (gagal bila stok tidak cukup). Revisi dan tolak sama seperti barang masuk.
     *
     * @throws \App\Exceptions\StokTidakCukup
     */
    public function putuskanBarangKeluar(BarangKeluar $dokumen, User $adminUtama, string $aksi, ?string $catatan): BarangKeluar
    {
        return $this->putuskanDokumenStok(BarangKeluar::class, $dokumen->id, $adminUtama, $aksi, $catatan);
    }

    /**
     * Keputusan per dokumen untuk dokumen yang mutasinya menunggu persetujuan.
     *
     * @param  class-string<BarangMasuk|BarangKeluar>  $kelas  model dengan konstanta DOKUMEN_TIPE dan STATUS_*
     */
    private function putuskanDokumenStok(string $kelas, int $dokumenId, User $adminUtama, string $aksi, ?string $catatan): Model
    {
        return DB::transaction(function () use ($kelas, $dokumenId, $adminUtama, $aksi, $catatan) {
            $dokumen = $kelas::whereKey($dokumenId)->lockForUpdate()->firstOrFail();

            if ($dokumen->status !== $kelas::STATUS_MENUNGGU) {
                throw new DomainException('Dokumen ini sudah diputuskan atau sedang diperbaiki pencatat.');
            }

            [$status, $keputusan] = match ($aksi) {
                self::AKSI_SETUJUI => [$kelas::STATUS_DISETUJUI, Persetujuan::KEPUTUSAN_DISETUJUI],
                self::AKSI_REVISI  => [$kelas::STATUS_REVISI, Persetujuan::KEPUTUSAN_REVISI],
                self::AKSI_TOLAK   => [$kelas::STATUS_BATAL, Persetujuan::KEPUTUSAN_DITOLAK],
            };

            if ($aksi === self::AKSI_SETUJUI) {
                $this->stok->sahkan($kelas::DOKUMEN_TIPE, $dokumen->id);
            } else {
                $this->stok->batalkan($kelas::DOKUMEN_TIPE, $dokumen->id);
            }

            $dokumen->update(['status' => $status]);
            $this->catat($kelas::DOKUMEN_TIPE, $dokumen->id, null, $keputusan, $adminUtama, $catatan);
            $this->notifikasi->selesaikan(route('admin-utama.persetujuan.'.($kelas === BarangMasuk::class ? 'barang-masuk' : 'barang-keluar'), $dokumen, false));

            $jenis = $kelas === BarangMasuk::class ? 'Barang masuk' : 'Barang rusak';
            $lokasi = $dokumen->cabang;
            $this->notifikasi->keLokasi(
                $lokasi,
                $jenis.' '.$dokumen->nomor.': '.mb_strtolower(Persetujuan::LABEL_KEPUTUSAN[$keputusan]),
                $catatan,
                route(NotifikasiService::awalanRute($lokasi).'.'.($kelas === BarangMasuk::class ? 'barang-masuk' : 'barang-keluar').'.lihat', $dokumen, false)
            );

            return $dokumen;
        });
    }

    /**
     * Putuskan satu stock opname.
     * Disetujui: stok sistem disamakan dengan hasil hitung (mutasi koreksi_opname), kunci stok dibuka.
     * Revisi: dikembalikan untuk dihitung ulang (stok tetap dikunci). Tolak: dibatalkan tanpa koreksi, kunci dibuka.
     */
    public function putuskanOpname(Opname $opname, User $adminUtama, string $aksi, ?string $catatan): Opname
    {
        return DB::transaction(function () use ($opname, $adminUtama, $aksi, $catatan) {
            $opname = Opname::whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($opname->status !== Opname::STATUS_DIKIRIM) {
                throw new DomainException('Opname ini sudah diputuskan atau sedang dihitung ulang.');
            }

            [$status, $keputusan, $bukaKunci] = match ($aksi) {
                self::AKSI_SETUJUI => [Opname::STATUS_DISETUJUI, Persetujuan::KEPUTUSAN_DISETUJUI, true],
                self::AKSI_REVISI  => [Opname::STATUS_BERJALAN, Persetujuan::KEPUTUSAN_REVISI, false],
                self::AKSI_TOLAK   => [Opname::STATUS_BATAL, Persetujuan::KEPUTUSAN_DITOLAK, true],
            };

            if ($aksi === self::AKSI_SETUJUI) {
                $this->koreksiStok($opname, $adminUtama);
            }

            $opname->update(['status' => $status]);
            $this->catat(Opname::DOKUMEN_TIPE, $opname->id, null, $keputusan, $adminUtama, $catatan);
            $this->notifikasi->selesaikan(route('admin-utama.persetujuan.opname', $opname, false));

            if ($bukaKunci) {
                Cabang::whereKey($opname->cabang_id)->update(['opname_aktif' => false]);
            }

            $lokasi = $opname->cabang;
            $this->notifikasi->keLokasi(
                $lokasi,
                'Opname '.$opname->nomor.': '.mb_strtolower(Persetujuan::LABEL_KEPUTUSAN[$keputusan]),
                $catatan ?? ($bukaKunci ? 'Stok lokasi tidak lagi dikunci.' : null),
                route(NotifikasiService::awalanRute($lokasi).'.opname.lihat', $opname, false)
            );

            return $opname;
        });
    }

    /** Mutasi koreksi = hasil hitung fisik - stok sistem saat ini, untuk bahan yang berbeda. */
    private function koreksiStok(Opname $opname, User $adminUtama): void
    {
        $saldo = DB::table('stok_saldo')->where('cabang_id', $opname->cabang_id)->pluck('saldo', 'bahan_baku_id');
        $mutasi = [];

        foreach ($opname->detail()->whereNotNull('qty_fisik')->get() as $d) {
            $koreksi = round((float) $d->qty_fisik - (float) ($saldo[$d->bahan_baku_id] ?? 0), 3);

            if ($koreksi != 0.0) {
                $mutasi[] = [
                    'cabang_id'         => $opname->cabang_id,
                    'bahan_baku_id'     => $d->bahan_baku_id,
                    'jumlah'            => $koreksi,
                    'dokumen_detail_id' => $d->id,
                ];
            }
        }

        if ($mutasi !== []) {
            $this->stok->catat($mutasi, StokMutasi::JENIS_KOREKSI_OPNAME, Opname::DOKUMEN_TIPE, $opname->id, $adminUtama->id);
            $this->stok->sahkan(Opname::DOKUMEN_TIPE, $opname->id, bolehMinus: true);
        }
    }

    /**
     * Tolak otomatis barang permintaan yang belum diputuskan sampai batas waktunya.
     *
     * @return int jumlah barang yang ditolak
     */
    public function tolakOtomatis(?Carbon $sekarang = null): int
    {
        $sekarang ??= now();
        $jumlah = 0;

        Permintaan::query()
            ->where('status', Permintaan::STATUS_MENUNGGU)
            ->where('batas_keputusan', '<', $sekarang)
            ->whereHas('detail', fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU))
            ->pluck('id')
            ->each(function (int $id) use (&$jumlah) {
                $jumlah += DB::transaction(function () use ($id) {
                    $permintaan = Permintaan::whereKey($id)->lockForUpdate()->firstOrFail();
                    $detail = $permintaan->detail()->where('status', PermintaanDetail::STATUS_MENUNGGU)->lockForUpdate()->get();

                    foreach ($detail as $d) {
                        $d->update(['status' => PermintaanDetail::STATUS_OTOMATIS_DITOLAK, 'qty_disetujui' => 0]);
                        $this->catat(
                            Permintaan::DOKUMEN_TIPE, $permintaan->id, $d->id,
                            Persetujuan::KEPUTUSAN_OTOMATIS_DITOLAK, null, self::CATATAN_LEWAT_BATAS
                        );
                    }

                    $this->tutupBilaTakAdaYangDikirim($permintaan);
                    $this->selesaikanNotifikasiPermintaan($permintaan);

                    $this->notifikasi->keLokasi(
                        $permintaan->cabang_id,
                        'Permintaan '.$permintaan->nomor.' ditolak otomatis',
                        $detail->count().' barang tidak diputuskan Admin Utama sampai batas waktu. Ajukan lagi bila masih perlu.',
                        route('cabang.permintaan.lihat', $permintaan, false)
                    );

                    return $detail->count();
                });
            });

        return $jumlah;
    }

    /** Notifikasi "permintaan menunggu" untuk Admin Utama dianggap selesai bila tidak ada lagi barang yang menunggu keputusan. */
    private function selesaikanNotifikasiPermintaan(Permintaan $permintaan): void
    {
        if (! $permintaan->detail()->where('status', PermintaanDetail::STATUS_MENUNGGU)->exists()) {
            $this->notifikasi->selesaikan(route('admin-utama.persetujuan.permintaan', $permintaan, false));
        }
    }

    private function beritahuKeputusanPermintaan(Permintaan $permintaan, int $disetujui, int $ditolak): void
    {
        if ($disetujui + $ditolak === 0) {
            return;
        }

        $this->notifikasi->keLokasi(
            $permintaan->cabang_id,
            'Permintaan '.$permintaan->nomor.' diputuskan',
            "{$disetujui} barang disetujui, {$ditolak} ditolak.",
            route('cabang.permintaan.lihat', $permintaan, false)
        );

        if ($disetujui > 0) {
            $this->notifikasi->kePusat(
                'Siap dikirim ke '.$permintaan->cabang->nama,
                "{$disetujui} barang disetujui Admin Utama · {$permintaan->nomor}",
                route('pusat.kiriman.buat', $permintaan, false)
            );
        }
    }

    /** Permintaan yang semua barangnya sudah diputuskan dan tidak ada yang disetujui langsung selesai. */
    private function tutupBilaTakAdaYangDikirim(Permintaan $permintaan): void
    {
        $masihMenunggu = $permintaan->detail()->where('status', PermintaanDetail::STATUS_MENUNGGU)->exists();
        $adaDisetujui = $permintaan->detail()->where('status', PermintaanDetail::STATUS_DISETUJUI)->exists();

        if (! $masihMenunggu && ! $adaDisetujui) {
            $permintaan->update(['status' => Permintaan::STATUS_SELESAI]);
        }
    }

    private function catat(string $tipe, int $dokumenId, ?int $detailId, string $keputusan, ?User $oleh, ?string $catatan, ?float $qty = null): void
    {
        Persetujuan::create([
            'dokumen_tipe'      => $tipe,
            'dokumen_id'        => $dokumenId,
            'dokumen_detail_id' => $detailId,
            'keputusan'         => $keputusan,
            'qty_disetujui'     => $qty,
            'catatan'           => $catatan,
            'oleh_user_id'      => $oleh?->id,
        ]);
    }
}
