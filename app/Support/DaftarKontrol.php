<?php

namespace App\Support;

use App\Models\User;

/**
 * Daftar modul yang bisa dinyalakan/dimatikan dan menu per peran yang hak aksesnya diatur di Pengaturan.
 * Ini daftar fitur aplikasi (bukan master data); nilai nyala/mati-nya disimpan di tabel kontrol_sistem.
 *
 * Menu yang menjadi mata rantai stok (barang masuk -> gudang -> kiriman -> cabang -> penjualan) dan barang rusak dikunci:
 * tidak bisa dimatikan. Ubah 'kunci' di bawah bila keputusan berubah.
 */
final class DaftarKontrol
{
    public const MODUL_PERMINTAAN_OTOMATIS = 'permintaan_otomatis';
    public const MODUL_TOLAK_OTOMATIS = 'tolak_otomatis';
    public const MODUL_PERINGATAN_DINI = 'peringatan_dini';

    /** Modul: label, keterangan, dan akibatnya bila dimatikan (untuk konfirmasi). */
    public const MODUL = [
        self::MODUL_PERMINTAAN_OTOMATIS => [
            'label'      => 'Permintaan Otomatis',
            'keterangan' => 'Setiap hari membuat permintaan untuk bahan cabang yang di bawah minimum.',
            'efek_mati'  => 'Permintaan otomatis tidak dibuat lagi. Cabang harus mengajukan permintaan sendiri.',
        ],
        self::MODUL_TOLAK_OTOMATIS => [
            'label'      => 'Tolak Otomatis',
            'keterangan' => 'Permintaan yang lewat batas waktu persetujuan ditolak otomatis.',
            'efek_mati'  => 'Permintaan yang lewat batas waktu tetap menunggu keputusan.',
        ],
        self::MODUL_PERINGATAN_DINI => [
            'label'      => 'Peringatan',
            'keterangan' => 'Daftar hal yang patut dicek di dashboard Admin Utama.',
            'efek_mati'  => 'Panel peringatan dini di dashboard disembunyikan.',
        ],
    ];

    public const AKSI_LIHAT = 'lihat';
    public const AKSI_CATAT = 'catat';

    public const LABEL_AKSI = [
        self::AKSI_LIHAT => 'Lihat',
        self::AKSI_CATAT => 'Catat',
    ];

    /** Bagian terakhir nama route GET yang berupa form pencatatan, jadi termasuk aksi catat. */
    public const RUTE_FORM_CATAT = ['catat', 'buat', 'ubah', 'impor'];

    /** Peran yang hak aksesnya bisa diatur, dengan potongan alamatnya. */
    public const PERAN = [
        'pusat'  => User::ROLE_ADMIN_PUSAT,
        'cabang' => User::ROLE_ADMIN_CABANG,
    ];

    /**
     * Menu per peran.
     *   label  nama di layar
     *   rute   bagian kedua nama route (pusat.{rute}..., cabang.{rute}...), atau bagian kedua dan ketiga (mis. stok.tambah)
     *          untuk aksi tertentu di dalam halaman lain; rute bertitik selalu termasuk aksi catat
     *   aksi   aksi yang ada di menu ini
     *   kunci  aksi yang selalu boleh (tidak bisa dimatikan)
     *   mati   aksi yang bawaannya mati sampai dinyalakan Admin Utama (opsional)
     *   awalan bagian pertama nama route bila bukan milik peran itu sendiri (opsional); 'admin-utama' = menu Admin Utama
     *          yang dibagikan ke peran ini, bawaannya mati
     *   grup   judul kelompok di tabel hak akses (opsional)
     */
    public const MENU = [
        User::ROLE_ADMIN_PUSAT => [
            'stok'          => ['label' => 'Dashboard & Stok', 'rute' => ['dashboard', 'stok'], 'aksi' => [self::AKSI_LIHAT], 'kunci' => [self::AKSI_LIHAT]],
            'ubah-stok'     => ['label' => 'Tambah Stok', 'rute' => ['stok.tambah', 'stok.isi'], 'aksi' => [self::AKSI_CATAT], 'kunci' => [], 'mati' => [self::AKSI_CATAT]],
            'barang-masuk'  => ['label' => 'Barang Masuk', 'rute' => ['barang-masuk'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'kiriman'       => ['label' => 'Kiriman', 'rute' => ['kiriman'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'terima-barang' => ['label' => 'Terima Retur', 'rute' => ['terima-barang'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'barang-keluar' => ['label' => 'Barang Rusak', 'rute' => ['barang-keluar'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'opname'        => ['label' => 'Opname', 'rute' => ['opname'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => []],
            ...self::MENU_UTAMA,
        ],
        User::ROLE_ADMIN_CABANG => [
            'stok'          => ['label' => 'Dashboard & Stok', 'rute' => ['dashboard', 'stok'], 'aksi' => [self::AKSI_LIHAT], 'kunci' => [self::AKSI_LIHAT]],
            'terima-barang' => ['label' => 'Terima Barang', 'rute' => ['terima-barang'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'kirim-barang'  => ['label' => 'Kirim Barang', 'rute' => ['kirim-barang'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT]],
            'penjualan'     => ['label' => 'Penjualan', 'rute' => ['penjualan'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'permintaan'    => ['label' => 'Permintaan', 'rute' => ['permintaan', 'stok.isi'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT]],
            'barang-keluar' => ['label' => 'Barang Rusak', 'rute' => ['barang-keluar'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => [self::AKSI_LIHAT, self::AKSI_CATAT]],
            'opname'        => ['label' => 'Opname', 'rute' => ['opname'], 'aksi' => [self::AKSI_LIHAT, self::AKSI_CATAT], 'kunci' => []],
        ],
    ];

    public const AWALAN_UTAMA = 'admin-utama';

    public const GRUP_UTAMA = 'Menu Admin Utama';

    /**
     * Menu Admin Utama yang bisa dibagikan ke Admin Pusat, bawaannya mati. Satu sakelar per menu (disimpan sebagai aksi
     * lihat) yang membuka seluruh halaman dan aksinya, sama seperti Admin Utama. Pengaturan (termasuk hak akses, modul,
     * periode buku, identitas usaha) tidak ada di sini, jadi selalu khusus Admin Utama. Dashboard dan Kiriman Admin Utama
     * tidak dibagikan karena Admin Pusat sudah punya halamannya sendiri.
     */
    private const MENU_UTAMA = [
        'utama-persetujuan'   => ['label' => 'Persetujuan', 'rute' => ['persetujuan']] + self::DASAR_UTAMA,
        'utama-stok'          => ['label' => 'Stok Semua Lokasi', 'rute' => ['stok']] + self::DASAR_UTAMA,
        'utama-stok-minimum'  => ['label' => 'Stok Minimum', 'rute' => ['stok-minimum']] + self::DASAR_UTAMA,
        'utama-laporan'       => ['label' => 'Laporan', 'rute' => ['laporan']] + self::DASAR_UTAMA,
        'utama-penjualan'     => ['label' => 'Catatan Penjualan', 'rute' => ['penjualan']] + self::DASAR_UTAMA,
        'utama-bahan-baku'    => ['label' => 'Bahan Baku', 'rute' => ['bahan-baku']] + self::DASAR_UTAMA,
        'utama-kategori'      => ['label' => 'Kategori Bahan', 'rute' => ['kategori']] + self::DASAR_UTAMA,
        'utama-satuan'        => ['label' => 'Satuan', 'rute' => ['satuan']] + self::DASAR_UTAMA,
        'utama-lokasi'        => ['label' => 'Lokasi', 'rute' => ['lokasi']] + self::DASAR_UTAMA,
        'utama-kategori-menu' => ['label' => 'Kategori Menu', 'rute' => ['kategori-menu']] + self::DASAR_UTAMA,
        'utama-menu'          => ['label' => 'Menu & Resep', 'rute' => ['menu']] + self::DASAR_UTAMA,
        'utama-pengguna'         => ['label' => 'Pengguna', 'rute' => ['pengguna']] + self::DASAR_UTAMA,
        'utama-impor-sekaligus' => ['label' => 'Impor Sekaligus', 'rute' => ['impor-sekaligus']] + self::DASAR_UTAMA,
    ];

    /** Nilai bersama menu Admin Utama yang dibagikan: satu aksi (lihat) yang bawaannya mati. */
    private const DASAR_UTAMA = [
        'aksi'   => [self::AKSI_LIHAT],
        'kunci'  => [],
        'mati'   => [self::AKSI_LIHAT],
        'awalan' => self::AWALAN_UTAMA,
        'grup'   => self::GRUP_UTAMA,
    ];

    /** Bagian awal (sebelum titik) nama route impor/ekspor Excel per master, bila berbeda dari nama masternya. */
    private const MENU_EXCEL = ['resep' => 'menu'];

    /**
     * Akses yang hanya boleh mati selama modulnya menyala (supaya rantai stok tidak putus).
     * Format kunci: peran.menu.aksi => modul.
     */
    public const SYARAT_MODUL = [
        User::ROLE_ADMIN_CABANG.'.permintaan.'.self::AKSI_CATAT => self::MODUL_PERMINTAAN_OTOMATIS,
    ];

    /** Menu dan aksi untuk sebuah route, atau null bila route itu tidak diatur hak aksesnya. */
    public static function aksiRute(string $role, ?string $namaRute, string $metode): ?array
    {
        $bagian = explode('.', (string) $namaRute);

        if (count($bagian) < 2) {
            return null;
        }

        if (count($bagian) > 2) {
            foreach (self::menuPeran($role) as $menu => $definisi) {
                if (! self::awalanCocok($definisi, $bagian[0])) {
                    continue;
                }

                if (in_array($bagian[1].'.'.$bagian[2], $definisi['rute'], true)) {
                    return [$menu, self::AKSI_CATAT];
                }
            }
        }

        foreach (self::menuPeran($role) as $menu => $definisi) {
            if (! self::awalanCocok($definisi, $bagian[0]) || ! in_array($bagian[1], $definisi['rute'], true)) {
                continue;
            }

            if (isset($definisi['awalan'])) {
                return [$menu, self::AKSI_LIHAT];
            }

            $catat = ! in_array(strtoupper($metode), ['GET', 'HEAD'], true)
                || (count($bagian) > 2 && in_array(end($bagian), self::RUTE_FORM_CATAT, true));

            return [$menu, $catat ? self::AKSI_CATAT : self::AKSI_LIHAT];
        }

        return null;
    }

    /** @return array<string, array{label: string, rute: list<string>, aksi: list<string>, kunci: list<string>, mati?: list<string>, awalan?: string, grup?: string}> */
    public static function menuPeran(string $role): array
    {
        return self::MENU[$role] ?? [];
    }

    /** Menu milik peran sendiri hanya cocok dengan route di luar admin-utama; menu yang dibagikan hanya dengan admin-utama. */
    private static function awalanCocok(array $definisi, string $awalan): bool
    {
        return isset($definisi['awalan']) ? $definisi['awalan'] === $awalan : $awalan !== self::AWALAN_UTAMA;
    }

    /**
     * Nama route yang dipakai untuk mencocokkan hak akses. Impor/ekspor Excel dan Riwayat Admin Utama dipakai banyak menu,
     * jadi diterjemahkan ke menu pemiliknya: admin-utama.excel.ekspor + master satuan -> admin-utama.satuan.ekspor,
     * impor -> admin-utama.satuan.impor (aksi catat), admin-utama.riwayat + ?jenis= -> {rute riwayat}.riwayat.
     */
    public static function namaRuteEfektif(?string $namaRute, ?string $master = null, ?string $jenis = null): ?string
    {
        $awal = self::AWALAN_UTAMA.'.';

        if ($namaRute !== null && str_starts_with($namaRute, $awal.'excel.') && is_string($master)) {
            $bagian = self::MENU_EXCEL[$master] ?? $master;

            return $awal.$bagian.($namaRute === $awal.'excel.ekspor' ? '.ekspor' : '.impor');
        }

        if ($namaRute === $awal.'riwayat') {
            $rute = DaftarRiwayat::JENIS[(string) $jenis]['rute'] ?? null;

            return $rute === null ? $namaRute : $rute.'.riwayat';
        }

        return $namaRute;
    }

    /** Aksi pada satu menu yang bisa dinyalakan/dimatikan Admin Utama. */
    public static function bisaDiatur(array $definisi): array
    {
        return array_values(array_diff($definisi['aksi'], $definisi['kunci']));
    }

    public static function peranDariPotongan(string $potongan): ?string
    {
        return self::PERAN[$potongan] ?? null;
    }
}
