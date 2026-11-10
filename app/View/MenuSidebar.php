<?php

namespace App\View;

use App\Models\User;
use App\Services\KontrolSistemService;

/**
 * Daftar menu per role, dipakai sidebar/laci dan navigasi bawah HP.
 *
 * Kunci item:
 *   route  nama route
 *   label  teks menu
 *   pendek teks di navigasi bawah HP (opsional, default = label)
 *   ikon   nama ikon di components/ikon.blade.php
 *   jumlah kunci $jumlah (JumlahMenu) untuk angka penanda, atau null
 *   utama  true = tampil juga di navigasi bawah HP (maksimal 4 per role)
 *   cari   true = tujuan kotak "Cari bahan" di kepala halaman (satu per role, halaman yang menerima ?cari=)
 *   modul  modul yang harus menyala supaya menu tampil (opsional, lihat DaftarKontrol::MODUL)
 *
 * Menu juga disembunyikan bila hak akses "lihat" peran itu dimatikan di Pengaturan. Grup Admin Utama milik Admin Pusat
 * hanya berisi menu yang diizinkan Admin Utama di Pengaturan > Hak Akses (bawaannya kosong).
 * Menu baru ditambahkan di sini bersamaan dengan route-nya.
 */
class MenuSidebar
{
    public const MAKS_MENU_UTAMA = 4;

    /** Menu yang sama untuk semua peran. */
    private const MENU_AKUN = [
        'Akun' => [
            ['route' => 'notifikasi', 'label' => 'Notifikasi', 'ikon' => 'lonceng', 'jumlah' => 'notifikasi_belum_dibaca', 'utama' => false],
            ['route' => 'akun', 'label' => 'Akun', 'ikon' => 'kunci', 'jumlah' => null, 'utama' => false],
            ['route' => 'bantuan', 'label' => 'Bantuan', 'ikon' => 'bantuan', 'jumlah' => null, 'utama' => false],
        ],
    ];

    private const MENU = [
        User::ROLE_ADMIN_UTAMA => [
            'Utama' => [
                ['route' => 'admin-utama.dashboard', 'label' => 'Dashboard', 'pendek' => 'Beranda', 'ikon' => 'dashboard', 'jumlah' => null, 'utama' => true],
            ],
            'Transaksi' => [
                ['route' => 'admin-utama.persetujuan', 'label' => 'Persetujuan', 'ikon' => 'ceklis', 'jumlah' => 'persetujuan_menunggu', 'utama' => true],
                ['route' => 'admin-utama.kiriman', 'label' => 'Kiriman', 'ikon' => 'truk', 'jumlah' => 'permintaan_belum_dikirim', 'utama' => false],
            ],
            'Persediaan' => [
                ['route' => 'admin-utama.stok', 'label' => 'Stok', 'ikon' => 'kotak', 'jumlah' => null, 'utama' => true, 'cari' => true],
                ['route' => 'admin-utama.stok-minimum', 'label' => 'Stok Minimum', 'ikon' => 'peringatan', 'jumlah' => null, 'utama' => false],
            ],
            'Laporan' => [
                ['route' => 'admin-utama.laporan', 'label' => 'Laporan', 'ikon' => 'grafik', 'jumlah' => null, 'utama' => true],
            ],
            'Data Master' => [
                ['route' => 'admin-utama.bahan-baku', 'label' => 'Bahan Baku', 'ikon' => 'kotak', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.kategori', 'label' => 'Kategori Bahan', 'ikon' => 'label', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.satuan', 'label' => 'Satuan', 'ikon' => 'timbangan', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.lokasi', 'label' => 'Lokasi', 'ikon' => 'lokasi', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.kategori-menu', 'label' => 'Kategori Menu', 'ikon' => 'label', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.menu', 'label' => 'Menu & Resep', 'ikon' => 'mangkuk', 'jumlah' => null, 'utama' => false],
            ],
            'Sistem' => [
                ['route' => 'admin-utama.pengguna', 'label' => 'Pengguna', 'ikon' => 'pengguna', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.pengaturan', 'label' => 'Pengaturan', 'ikon' => 'gear', 'jumlah' => null, 'utama' => false, 'juga' => ['admin-utama.kontrol-sistem', 'admin-utama.kontrol-sistem.*']],
            ],
            ...self::MENU_AKUN,
        ],
        User::ROLE_ADMIN_PUSAT => [
            'Utama' => [
                ['route' => 'pusat.dashboard', 'label' => 'Dashboard', 'pendek' => 'Beranda', 'ikon' => 'dashboard', 'jumlah' => null, 'utama' => true],
            ],
            'Transaksi' => [
                ['route' => 'pusat.kiriman', 'label' => 'Kiriman', 'ikon' => 'truk', 'jumlah' => 'permintaan_belum_dikirim', 'utama' => true],
                ['route' => 'pusat.barang-masuk', 'label' => 'Barang Masuk', 'pendek' => 'Masuk', 'ikon' => 'masuk', 'jumlah' => null, 'utama' => true],
                ['route' => 'pusat.terima-barang', 'label' => 'Terima Retur', 'ikon' => 'masuk', 'jumlah' => 'kiriman_belum_dichecklist', 'utama' => false],
                ['route' => 'pusat.barang-keluar', 'label' => 'Barang Rusak', 'ikon' => 'keluar', 'jumlah' => null, 'utama' => false],
            ],
            'Persediaan' => [
                ['route' => 'pusat.stok', 'label' => 'Stok', 'pendek' => 'Stok', 'ikon' => 'kotak', 'jumlah' => null, 'utama' => true, 'cari' => true],
                ['route' => 'pusat.opname', 'label' => 'Opname', 'ikon' => 'ceklis', 'jumlah' => null, 'utama' => false],
            ],
            'Admin Utama' => [
                ['route' => 'admin-utama.persetujuan', 'label' => 'Persetujuan', 'ikon' => 'ceklis', 'jumlah' => 'persetujuan_menunggu', 'utama' => false],
                ['route' => 'admin-utama.stok', 'label' => 'Stok Semua Lokasi', 'ikon' => 'kotak', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.stok-minimum', 'label' => 'Stok Minimum', 'ikon' => 'peringatan', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.laporan', 'label' => 'Laporan', 'ikon' => 'grafik', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.penjualan', 'label' => 'Catatan Penjualan', 'ikon' => 'kalender', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.bahan-baku', 'label' => 'Bahan Baku', 'ikon' => 'kotak', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.kategori', 'label' => 'Kategori Bahan', 'ikon' => 'label', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.satuan', 'label' => 'Satuan', 'ikon' => 'timbangan', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.lokasi', 'label' => 'Lokasi', 'ikon' => 'lokasi', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.kategori-menu', 'label' => 'Kategori Menu', 'ikon' => 'label', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.menu', 'label' => 'Menu & Resep', 'ikon' => 'mangkuk', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.pengguna', 'label' => 'Pengguna', 'ikon' => 'pengguna', 'jumlah' => null, 'utama' => false],
                ['route' => 'admin-utama.impor-sekaligus', 'label' => 'Impor Sekaligus', 'ikon' => 'keluar', 'jumlah' => null, 'utama' => false],
            ],
            ...self::MENU_AKUN,
        ],
        User::ROLE_ADMIN_CABANG => [
            'Utama' => [
                ['route' => 'cabang.dashboard', 'label' => 'Dashboard', 'pendek' => 'Beranda', 'ikon' => 'dashboard', 'jumlah' => null, 'utama' => true],
            ],
            'Transaksi' => [
                ['route' => 'cabang.penjualan', 'label' => 'Penjualan', 'pendek' => 'Penjualan', 'ikon' => 'grafik', 'jumlah' => null, 'utama' => true],
                ['route' => 'cabang.terima-barang', 'label' => 'Terima Barang', 'pendek' => 'Terima', 'ikon' => 'masuk', 'jumlah' => 'kiriman_belum_dichecklist', 'utama' => true],
                ['route' => 'cabang.permintaan', 'label' => 'Permintaan', 'pendek' => 'Minta', 'ikon' => 'panah-kiri', 'jumlah' => null, 'utama' => false],
                ['route' => 'cabang.kirim-barang', 'label' => 'Kirim Barang', 'ikon' => 'truk', 'jumlah' => null, 'utama' => false],
                ['route' => 'cabang.barang-keluar', 'label' => 'Barang Rusak', 'ikon' => 'keluar', 'jumlah' => null, 'utama' => false],
            ],
            'Persediaan' => [
                ['route' => 'cabang.stok', 'label' => 'Stok', 'pendek' => 'Stok', 'ikon' => 'kotak', 'jumlah' => null, 'utama' => true, 'cari' => true],
                ['route' => 'cabang.opname', 'label' => 'Opname', 'ikon' => 'ceklis', 'jumlah' => 'opname_berjalan', 'utama' => false],
            ],
            ...self::MENU_AKUN,
        ],
    ];

    /** Kunci = judul grup menu. @return array<string, list<array{route: string, label: string, pendek?: string, ikon: string, jumlah: ?string, utama: bool, cari?: bool}>> */
    public static function untuk(User $user): array
    {
        $kontrol = app(KontrolSistemService::class);

        return collect(self::MENU[$user->role] ?? [])
            ->map(fn (array $grup) => array_values(array_filter($grup, fn (array $item) => self::tampil($kontrol, $user, $item))))
            ->filter()
            ->all();
    }

    private static function tampil(KontrolSistemService $kontrol, User $user, array $item): bool
    {
        if (isset($item['modul']) && ! $kontrol->modulNyala($item['modul'])) {
            return false;
        }

        return $kontrol->bolehRute($user, $item['route']);
    }

    /** Menu yang ditampilkan di navigasi bawah HP. */
    public static function utama(User $user): array
    {
        return collect(self::untuk($user))
            ->flatten(1)
            ->where('utama', true)
            ->take(self::MAKS_MENU_UTAMA)
            ->values()
            ->all();
    }

    /** Nama route tujuan kotak cari di kepala halaman, atau null bila peran ini tidak punya. */
    public static function ruteCari(User $user): ?string
    {
        return collect(self::untuk($user))
            ->flatten(1)
            ->firstWhere('cari', true)['route'] ?? null;
    }

    /** Menu dianggap aktif juga untuk sub-halamannya, mis. admin-utama.satuan.ubah, dan route tambahan di 'juga'. */
    public static function sedangDibuka(array $item): bool
    {
        return request()->routeIs($item['route'], $item['route'].'.*', ...($item['juga'] ?? []));
    }
}
