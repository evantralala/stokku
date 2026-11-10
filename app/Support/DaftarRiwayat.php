<?php

namespace App\Support;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\Satuan;
use App\Models\User;

/**
 * Riwayat perubahan per fitur (dari log perubahan). Kunci = ?jenis= di halaman Riwayat.
 *   judul    nama fitur di layar
 *   rute     halaman fitur (untuk tautan kembali)
 *   model    data master yang perubahannya dicatat otomatis (CatatPerubahanData), atau
 *   aksi     awalan aksi log (impor Excel, resep, dll.), dan/atau
 *   kategori kategori log
 */
final class DaftarRiwayat
{
    public const JENIS = [
        'lokasi'        => ['judul' => 'Lokasi', 'rute' => 'admin-utama.lokasi', 'model' => Cabang::class],
        'kategori'      => ['judul' => 'Kategori Bahan', 'rute' => 'admin-utama.kategori', 'model' => Kategori::class, 'aksi' => ['impor.kategori-bahan']],
        'satuan'        => ['judul' => 'Satuan', 'rute' => 'admin-utama.satuan', 'model' => Satuan::class, 'aksi' => ['impor.satuan']],
        'bahan-baku'    => ['judul' => 'Bahan Baku', 'rute' => 'admin-utama.bahan-baku', 'model' => BahanBaku::class, 'aksi' => ['impor.bahan-baku']],
        'kategori-menu' => ['judul' => 'Kategori Menu', 'rute' => 'admin-utama.kategori-menu', 'model' => KategoriMenu::class, 'aksi' => ['impor.kategori-menu']],
        'menu'          => ['judul' => 'Menu & Resep', 'rute' => 'admin-utama.menu', 'model' => Menu::class, 'aksi' => ['resep.', 'impor.menu']],
        'stok-minimum'  => ['judul' => 'Stok Minimum', 'rute' => 'admin-utama.stok-minimum', 'aksi' => ['stok-minimum.', 'impor.stok-minimum']],
        'penjualan'     => ['judul' => 'Impor Penjualan', 'rute' => 'admin-utama.laporan.penjualan', 'aksi' => ['impor.penjualan-harian']],
        'pengguna'      => ['judul' => 'Pengguna', 'rute' => 'admin-utama.pengguna', 'kategori' => [LogPerubahan::KATEGORI_PENGGUNA, LogPerubahan::KATEGORI_AKUN]],
        'pengaturan'    => ['judul' => 'Pengaturan', 'rute' => 'admin-utama.pengaturan', 'kategori' => [LogPerubahan::KATEGORI_PENGATURAN]],
        'tambah-stok'   => ['judul' => 'Tambah Stok', 'rute' => 'admin-utama.stok', 'aksi' => ['stok.tambah']],
        'ubah-stok'     => ['judul' => 'Ubah Stok', 'rute' => 'admin-utama.stok', 'aksi' => ['stok.ubah']],
    ];

    /** Kolom yang tidak dicatat (otomatis atau dikelola alur lain). */
    public const KOLOM_ABAIKAN = ['id', 'created_at', 'updated_at', 'deleted_at', 'dihapus_pada', 'opname_aktif', 'remember_token', 'password'];

    /** Judul kolom di rincian; kolom lain memakai nama kolomnya. */
    public const LABEL_KOLOM = [
        'nama'                        => 'Nama',
        'foto'                        => 'Foto',
        'kode'                        => 'Kode',
        'aktif'                       => 'Status',
        'jenis'                       => 'Jenis',
        'alamat'                      => 'Alamat',
        'catatan'                     => 'Catatan',
        'urutan'                      => 'Urutan',
        'kategori_id'                 => 'Kategori',
        'kategori_menu_id'            => 'Kategori Menu',
        'satuan_dasar_id'             => 'Satuan Dasar',
        'satuan_beli_id'              => 'Satuan Beli',
        'isi_per_satuan_beli'         => 'Isi per Satuan Beli',
        'toleransi_persen'            => 'Toleransi (%)',
    ];

    /** Kolom kunci asing: tampilkan nama data yang dirujuk. */
    public const RUJUKAN = [
        'kategori_id'      => Kategori::class,
        'kategori_menu_id' => KategoriMenu::class,
        'satuan_dasar_id'  => Satuan::class,
        'satuan_beli_id'   => Satuan::class,
    ];

    /** Nama jenis data di ringkasan log. */
    public const NAMA_DATA = [
        Cabang::class       => 'Lokasi',
        Kategori::class     => 'Kategori bahan',
        Satuan::class       => 'Satuan',
        BahanBaku::class    => 'Bahan baku',
        KategoriMenu::class => 'Kategori menu',
        Menu::class         => 'Menu',
        User::class         => 'Pengguna',
    ];
}
