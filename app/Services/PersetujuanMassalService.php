<?php

namespace App\Services;

use App\Models\BarangMasuk;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\User;
use App\Support\AturanWajar;
use DomainException;

/**
 * "Setujui semua yang wajar": menyetujui beberapa dokumen sekaligus lewat jalur persetujuan biasa (PersetujuanService).
 * Setiap dokumen dicek ulang di server; yang tidak lagi wajar atau gagal disimpan dilewati beserta alasannya.
 */
class PersetujuanMassalService
{
    public function __construct(
        private PersetujuanService $persetujuan,
        private CekWajarService $cekWajar,
    ) {
    }

    /**
     * @param  list<string>  $kunci  mis. ["permintaan:12", "barang_masuk:4"]
     * @return array{disetujui: list<string>, dilewati: list<array{nomor: string, alasan: string}>}
     */
    public function setujuiYangWajar(array $kunci, User $adminUtama): array
    {
        $hasil = ['disetujui' => [], 'dilewati' => []];

        foreach (array_unique($kunci) as $k) {
            [$jenis, $id] = array_pad(explode(AturanWajar::PEMISAH_KUNCI, $k, 2), 2, null);
            $dokumen = match ($jenis) {
                Permintaan::DOKUMEN_TIPE  => Permintaan::query()->with('cabang')->find((int) $id),
                BarangMasuk::DOKUMEN_TIPE => BarangMasuk::query()->with('cabang')->find((int) $id),
                default                   => null,
            };

            if (! $dokumen) {
                continue;
            }

            $cek = $this->cekWajar->periksa($jenis, $dokumen->id, $dokumen->cabang_id, $dokumen->cabang->nama);

            if (! $cek['wajar']) {
                $hasil['dilewati'][] = ['nomor' => $dokumen->nomor, 'alasan' => $cek['alasan'] ?? 'Perlu dicek'];
                continue;
            }

            try {
                $this->setujui($dokumen, $adminUtama);
                $hasil['disetujui'][] = $dokumen->nomor;
            } catch (DomainException $e) {
                $hasil['dilewati'][] = ['nomor' => $dokumen->nomor, 'alasan' => $e->getMessage()];
            }
        }

        return $hasil;
    }

    private function setujui(Permintaan|BarangMasuk $dokumen, User $adminUtama): void
    {
        if ($dokumen instanceof BarangMasuk) {
            $this->persetujuan->putuskanBarangMasuk($dokumen, $adminUtama, PersetujuanService::AKSI_SETUJUI, null);

            return;
        }

        $keputusan = $dokumen->detail()
            ->where('status', PermintaanDetail::STATUS_MENUNGGU)
            ->with('bahanBaku:id,isi_per_satuan_beli')
            ->get()
            ->mapWithKeys(fn (PermintaanDetail $d) => [$d->id => [
                'aksi' => PersetujuanService::AKSI_SETUJUI,
                'qty'  => (float) $d->qty_diminta / (float) $d->bahanBaku->isi_per_satuan_beli,
            ]])
            ->all();

        $this->persetujuan->putuskanPermintaan($dokumen, $adminUtama, $keputusan);
    }
}
