<?php

namespace App\Support;

use App\Models\User;

/** Pilihan laporan email harian per peran, beserta yang tercentang bawaan. */
final class DaftarLaporanEmail
{
    public const STOK = 'stok';
    public const PENJUALAN = 'penjualan';
    public const DITERIMA = 'diterima';
    public const RUSAK = 'rusak';
    public const KIRIMAN = 'kiriman';
    public const MASUK = 'masuk';
    public const MENIPIS = 'menipis';
    public const PENJUALAN_SEMUA = 'penjualan_semua';
    public const MENUNGGU = 'menunggu';
    public const STOK_SEMUA = 'stok_semua';
    public const RUSAK_OPNAME = 'rusak_opname';

    /** Jam kirim harian (zona waktu aplikasi). */
    public const JAM_KIRIM = '23:59';

    /** Jam kirim susulan untuk laporan kemarin yang belum terkirim. */
    public const JAM_SUSULAN = '00:10';

    public const MAKS_ALAMAT = 10;

    /** [peran => [kunci => [label, tercentang bawaan]]], urut tampilan. */
    private const PILIHAN = [
        User::ROLE_ADMIN_CABANG => [
            self::STOK      => ['Stok terkini', true],
            self::PENJUALAN => ['Penjualan hari ini', false],
            self::DITERIMA  => ['Barang diterima hari ini', false],
            self::RUSAK     => ['Barang rusak hari ini', false],
        ],
        User::ROLE_ADMIN_PUSAT => [
            self::STOK    => ['Stok gudang pusat', true],
            self::KIRIMAN => ['Kiriman hari ini (keluar & diterima)', true],
            self::MASUK   => ['Barang masuk hari ini', false],
            self::RUSAK   => ['Barang rusak hari ini', false],
        ],
        User::ROLE_ADMIN_UTAMA => [
            self::MENIPIS         => ['Stok menipis & habis semua lokasi', true],
            self::PENJUALAN_SEMUA => ['Penjualan hari ini semua cabang', true],
            self::MENUNGGU        => ['Menunggu persetujuan', true],
            self::STOK_SEMUA      => ['Stok lengkap semua lokasi', false],
            self::RUSAK_OPNAME    => ['Barang rusak & selisih opname hari ini', false],
        ],
    ];

    /** @return array<string, string> [kunci => label] untuk peran itu */
    public static function pilihan(string $role): array
    {
        return array_map(fn (array $p) => $p[0], self::PILIHAN[$role] ?? []);
    }

    /** @return list<string> kunci yang tercentang bawaan */
    public static function bawaan(string $role): array
    {
        return array_keys(array_filter(self::PILIHAN[$role] ?? [], fn (array $p) => $p[1]));
    }

    /**
     * Kunci yang berlaku untuk peran itu, urut tampilan (kunci peran lain dibuang, mis. setelah peran akun diganti).
     *
     * @param  list<string>  $laporan
     * @return list<string>
     */
    public static function saring(string $role, array $laporan): array
    {
        return array_values(array_filter(array_keys(self::pilihan($role)), fn (string $k) => in_array($k, $laporan, true)));
    }
}
