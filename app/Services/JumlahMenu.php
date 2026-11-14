<?php

namespace App\Services;

use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Kiriman;
use App\Models\Notifikasi;
use App\Models\Opname;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\User;

/**
 * Menghitung angka penanda di menu.
 * Hasil selalu berisi semua kunci di KOSONG; kunci yang tidak relevan untuk role bernilai 0.
 */
class JumlahMenu
{
    public const KOSONG = [
        'persetujuan_menunggu' => 0,
        'notifikasi_belum_dibaca' => 0,
        'permintaan_belum_dikirim' => 0,
        'kiriman_aktif' => 0,
        'kiriman_belum_dichecklist' => 0,
        'opname_berjalan' => 0,
    ];

    public function untuk(?User $user): array
    {
        if (! $user) {
            return self::KOSONG;
        }

        $jumlah = self::KOSONG;

        $jumlah['notifikasi_belum_dibaca'] = Notifikasi::where('user_id', $user->id)
            ->belumDibaca()
            ->count();

        if ($user->isAdminUtama()) {
            $jumlah['persetujuan_menunggu'] = $this->persetujuanMenunggu();
            $jumlah['permintaan_belum_dikirim'] = $this->permintaanBelumDikirim();
        } elseif ($user->isAdminPusat()) {
            $jumlah['permintaan_belum_dikirim'] = $this->permintaanBelumDikirim();
            $jumlah['kiriman_aktif'] = $this->kirimanAktif();
            $jumlah['kiriman_belum_dichecklist'] = $user->cabang_id ? $this->kirimanBelumDichecklist($user->cabang_id) : 0;

            if (app(KontrolSistemService::class)->bolehRute($user, 'admin-utama.persetujuan')) {
                $jumlah['persetujuan_menunggu'] = $this->persetujuanMenunggu();
            }
        } elseif ($user->isAdminCabang() && $user->cabang_id) {
            $jumlah['kiriman_belum_dichecklist'] = $this->kirimanBelumDichecklist($user->cabang_id);
            $jumlah['opname_berjalan'] = $this->opnameBerjalan($user->cabang_id);
        }

        return $jumlah;
    }

    /** Jumlah dokumen yang menunggu keputusan, sama dengan antrean di halaman Persetujuan. */
    private function persetujuanMenunggu(): int
    {
        $permintaan = Permintaan::where('status', Permintaan::STATUS_MENUNGGU)
            ->whereHas('detail', fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU))
            ->count();

        $barangMasuk = BarangMasuk::where('status', BarangMasuk::STATUS_MENUNGGU)->count();
        $barangKeluar = BarangKeluar::where('status', BarangKeluar::STATUS_MENUNGGU)->count();
        $opname = Opname::where('status', Opname::STATUS_DIKIRIM)->count();

        return $permintaan + $barangMasuk + $barangKeluar + $opname;
    }

    /** Dokumen permintaan yang minimal satu barangnya belum terkirim penuh. */
    private function permintaanBelumDikirim(): int
    {
        return Permintaan::whereNotIn('status', ['draf', 'batal'])
            ->whereHas('detail', fn ($q) => $q->masihUtang())
            ->count();
    }

    /** Kiriman dari Gudang Pusat yang belum sampai. */
    private function kirimanAktif(): int
    {
        return Kiriman::whereIn('status', ['disiapkan', 'dikirim'])
            ->whereHas('dariCabang', fn ($q) => $q->pusat())
            ->count();
    }

    /** Hanya jumlah dokumen, tidak membuka qty_kirim (penerimaan buta). */
    /** Kiriman yang menuju lokasi ini dan belum diterima (cabang, atau Gudang Pusat untuk retur). */
    private function kirimanBelumDichecklist(int $cabangId): int
    {
        return Kiriman::where('ke_cabang_id', $cabangId)
            ->where('status', 'dikirim')
            ->count();
    }

    private function opnameBerjalan(int $cabangId): int
    {
        return Opname::where('cabang_id', $cabangId)
            ->where('status', 'berjalan')
            ->count();
    }
}
