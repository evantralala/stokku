<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pencatatan barang masuk Gudang Pusat.
 *
 * Saat dicatat, setiap baris membuat mutasi stok berstatus menunggu.
 * Stok baru bertambah ketika Admin Utama menyetujui (StokService::sahkan dipanggil di modul Persetujuan).
 */
class BarangMasukService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
    ) {
    }

    /**
     * @param  array{tanggal: string, catatan?: string|null, baris: array<int, array{bahan_baku_id: int|string, qty: float|string, keterangan?: string|null}>}  $data
     *         qty dalam satuan beli
     */
    public function catat(User $pencatat, Cabang $lokasi, array $data): BarangMasuk
    {
        return DB::transaction(function () use ($pencatat, $lokasi, $data) {
            $tanggal = Carbon::parse($data['tanggal']);

            $dokumen = BarangMasuk::create([
                'nomor'        => NomorDokumen::berikutnya('barang_masuk', BarangMasuk::AWALAN_NOMOR, $lokasi, $tanggal),
                'cabang_id'    => $lokasi->id,
                'tanggal'      => $tanggal->toDateString(),
                'catatan'      => $data['catatan'] ?? null,
                'dicatat_oleh' => $pencatat->id,
                'status'       => BarangMasuk::STATUS_MENUNGGU,
            ]);

            $this->simpanBaris($dokumen, $data['baris'], $pencatat);
            $this->beritahuAdminUtama($dokumen, $lokasi->nama, false);

            return $dokumen;
        });
    }

    /** Ganti isi dokumen yang masih menunggu atau perlu diperbaiki. Baris lama disimpan sebagai riwayat. */
    public function ubah(BarangMasuk $dokumen, User $pencatat, array $data): BarangMasuk
    {
        return DB::transaction(function () use ($dokumen, $pencatat, $data) {
            $dokumen = BarangMasuk::whereKey($dokumen->id)->lockForUpdate()->firstOrFail();
            $this->pastikanBisaDiubah($dokumen);

            $this->stok->batalkan(BarangMasuk::DOKUMEN_TIPE, $dokumen->id);
            $dokumen->detail()->delete();

            $dokumen->update([
                'tanggal' => Carbon::parse($data['tanggal'])->toDateString(),
                'catatan' => $data['catatan'] ?? null,
                'status'  => BarangMasuk::STATUS_MENUNGGU,
            ]);

            $this->simpanBaris($dokumen, $data['baris'], $pencatat);
            $this->beritahuAdminUtama($dokumen, $dokumen->cabang->nama, true);

            return $dokumen;
        });
    }

    public function batalkan(BarangMasuk $dokumen): BarangMasuk
    {
        return DB::transaction(function () use ($dokumen) {
            $dokumen = BarangMasuk::whereKey($dokumen->id)->lockForUpdate()->firstOrFail();
            $this->pastikanBisaDiubah($dokumen);

            $this->stok->batalkan(BarangMasuk::DOKUMEN_TIPE, $dokumen->id);
            $dokumen->update(['status' => BarangMasuk::STATUS_BATAL]);
            $this->notifikasi->selesaikan(route('admin-utama.persetujuan.barang-masuk', $dokumen, false));

            return $dokumen;
        });
    }

    private function simpanBaris(BarangMasuk $dokumen, array $baris, User $pencatat): void
    {
        $bahan = BahanBaku::query()
            ->whereKey(collect($baris)->pluck('bahan_baku_id')->all())
            ->get(['id', 'isi_per_satuan_beli'])
            ->keyBy('id');

        $mutasi = [];

        foreach ($baris as $b) {
            $qtyDasar = $bahan[(int) $b['bahan_baku_id']]->keSatuanDasar($b['qty']);

            $detail = $dokumen->detail()->create([
                'bahan_baku_id' => (int) $b['bahan_baku_id'],
                'qty'           => $qtyDasar,
                'keterangan'    => $b['keterangan'] ?? null,
            ]);

            $mutasi[] = [
                'cabang_id'           => $dokumen->cabang_id,
                'bahan_baku_id'       => $detail->bahan_baku_id,
                'jumlah'              => $qtyDasar,
                'dokumen_detail_id'   => $detail->id,
            ];
        }

        $this->stok->catat($mutasi, StokMutasi::JENIS_MASUK_SUPPLIER, BarangMasuk::DOKUMEN_TIPE, $dokumen->id, $pencatat->id);
    }

    private function beritahuAdminUtama(BarangMasuk $dokumen, string $lokasi, bool $perbaikan): void
    {
        $this->notifikasi->keAdminUtama(
            ($perbaikan ? 'Barang masuk diperbaiki: ' : 'Barang masuk baru: ').$lokasi,
            $dokumen->detail()->count().' barang · '.$dokumen->nomor,
            route('admin-utama.persetujuan.barang-masuk', $dokumen, false)
        );
    }

    private function pastikanBisaDiubah(BarangMasuk $dokumen): void
    {
        if (! $dokumen->bisaDiubah()) {
            throw new DomainException('Barang masuk ini sudah diputuskan Admin Utama, jadi tidak bisa diubah atau dibatalkan.');
        }
    }
}
