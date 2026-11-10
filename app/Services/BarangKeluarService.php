<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\NomorDokumen;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Barang rusak atau terbuang, dengan alasan per barang.
 * Saat dicatat, mutasi stok berstatus menunggu; stok berkurang ketika Admin Utama menyetujui.
 */
class BarangKeluarService
{
    public function __construct(
        private StokService $stok,
        private NotifikasiService $notifikasi,
    ) {
    }

    /**
     * @param  array{tanggal: string, baris: array<int, array{bahan_baku_id: int|string, qty: float|string, alasan: string}>}  $data
     *         qty dalam satuan beli
     */
    public function catat(User $pencatat, Cabang $lokasi, array $data): BarangKeluar
    {
        return DB::transaction(function () use ($pencatat, $lokasi, $data) {
            $tanggal = Carbon::parse($data['tanggal']);

            $dokumen = BarangKeluar::create([
                'nomor'        => NomorDokumen::berikutnya('barang_keluar', BarangKeluar::AWALAN_NOMOR, $lokasi, $tanggal),
                'cabang_id'    => $lokasi->id,
                'tanggal'      => $tanggal->toDateString(),
                'jenis'        => BarangKeluar::JENIS_RUSAK,
                'dicatat_oleh' => $pencatat->id,
                'status'       => BarangKeluar::STATUS_MENUNGGU,
            ]);

            $this->simpanBaris($dokumen, $data, $pencatat);
            $this->beritahuAdminUtama($dokumen, $lokasi->nama, false);

            return $dokumen;
        });
    }

    /** Ganti isi dokumen yang masih menunggu atau perlu diperbaiki. Baris lama disimpan sebagai riwayat. */
    public function ubah(BarangKeluar $dokumen, User $pencatat, array $data): BarangKeluar
    {
        return DB::transaction(function () use ($dokumen, $pencatat, $data) {
            $dokumen = BarangKeluar::whereKey($dokumen->id)->lockForUpdate()->firstOrFail();
            $this->pastikanBisaDiubah($dokumen);

            $this->stok->batalkan(BarangKeluar::DOKUMEN_TIPE, $dokumen->id);
            $dokumen->detail()->delete();

            $dokumen->update([
                'tanggal' => Carbon::parse($data['tanggal'])->toDateString(),
                'status'  => BarangKeluar::STATUS_MENUNGGU,
            ]);

            $this->simpanBaris($dokumen, $data, $pencatat);
            $this->beritahuAdminUtama($dokumen, $dokumen->cabang->nama, true);

            return $dokumen;
        });
    }

    public function batalkan(BarangKeluar $dokumen): BarangKeluar
    {
        return DB::transaction(function () use ($dokumen) {
            $dokumen = BarangKeluar::whereKey($dokumen->id)->lockForUpdate()->firstOrFail();
            $this->pastikanBisaDiubah($dokumen);

            $this->stok->batalkan(BarangKeluar::DOKUMEN_TIPE, $dokumen->id);
            $dokumen->update(['status' => BarangKeluar::STATUS_BATAL]);
            $this->notifikasi->selesaikan(route('admin-utama.persetujuan.barang-keluar', $dokumen, false));

            return $dokumen;
        });
    }

    private function simpanBaris(BarangKeluar $dokumen, array $data, User $pencatat): void
    {
        $bahan = BahanBaku::query()
            ->whereKey(collect($data['baris'])->pluck('bahan_baku_id')->all())
            ->get(['id', 'nama', 'isi_per_satuan_beli'])
            ->keyBy('id');

        $mutasi = [];

        foreach ($data['baris'] as $b) {
            $item = $bahan[(int) $b['bahan_baku_id']];
            $qty = $item->keSatuanDasar($b['qty']);
            $saldo = $this->stok->saldo($dokumen->cabang_id, $item->id);

            if ($qty > $saldo + 0.0005) {
                throw new DomainException("Jumlah {$item->nama} melebihi stok yang tercatat saat ini. Periksa lagi angkanya.");
            }

            $detail = $dokumen->detail()->create([
                'bahan_baku_id' => $item->id,
                'qty'           => $qty,
                'sebab'         => BarangKeluarDetail::SEBAB_RUSAK,
                'penjelasan'    => $b['alasan'],
            ]);

            $mutasi[] = [
                'cabang_id'         => $dokumen->cabang_id,
                'bahan_baku_id'     => $detail->bahan_baku_id,
                'jumlah'            => -$qty,
                'dokumen_detail_id' => $detail->id,
            ];
        }

        $this->stok->catat($mutasi, StokMutasi::JENIS_RUSAK, BarangKeluar::DOKUMEN_TIPE, $dokumen->id, $pencatat->id);
    }

    private function beritahuAdminUtama(BarangKeluar $dokumen, string $lokasi, bool $perbaikan): void
    {
        $this->notifikasi->keAdminUtama(
            ($perbaikan ? 'Barang rusak diperbaiki: ' : 'Barang rusak/terbuang dilaporkan: ').$lokasi,
            $dokumen->detail()->count().' barang · '.$dokumen->nomor,
            route('admin-utama.persetujuan.barang-keluar', $dokumen, false)
        );
    }

    private function pastikanBisaDiubah(BarangKeluar $dokumen): void
    {
        if (! $dokumen->bisaDiubah()) {
            throw new DomainException('Dokumen ini sudah diputuskan Admin Utama, jadi tidak bisa diubah atau dibatalkan.');
        }
    }
}
