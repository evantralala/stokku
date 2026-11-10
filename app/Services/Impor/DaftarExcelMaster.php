<?php

namespace App\Services\Impor;

/** Impor/Ekspor Excel milik Admin Utama. Kunci = bagian alamat /admin-utama/{kunci}/impor. */
final class DaftarExcelMaster
{
    public const KELAS = [
        'bahan-baku'    => ImporBahanService::class,
        'kategori'      => ImporKategoriService::class,
        'satuan'        => ImporSatuanService::class,
        'kategori-menu' => ImporKategoriMenuService::class,
        'menu'          => ImporMenuService::class,
        'lokasi'        => EksporLokasiService::class,
        'pengguna'      => EksporPenggunaService::class,
        'resep'         => ImporResepService::class,
        'penjualan'     => ImporPenjualanService::class,
        'stok-minimum'  => ImporStokMinimumService::class,
    ];

    /** Halaman tujuan setelah impor bila bukan admin-utama.{kunci}. */
    public const RUTE_KEMBALI = [
        'resep'     => 'admin-utama.menu',
        'penjualan' => 'admin-utama.laporan.penjualan',
    ];

    public static function layanan(string $master): ImporExcel
    {
        return app(self::KELAS[$master]);
    }
}
