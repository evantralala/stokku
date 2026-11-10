<?php

namespace App\Support;

use App\Models\Pengaturan;
use Illuminate\Support\Carbon;

/**
 * Hari operasional penjualan: penjualan dicatat setelah closing tengah malam, jadi sebelum jam ganti hari
 * (Pengaturan jam_ganti_hari_penjualan, bawaan 06.00) tanggal bawaan penjualan masih hari kemarin.
 * Hanya mengubah tanggal bawaan (form, templat, impor tanpa kolom Tanggal, pengingat); tanggal tetap bisa dipilih.
 */
final class HariOperasional
{
    public const JAM_BAWAAN = '06:00';

    public static function jamGanti(): string
    {
        $jam = (string) Pengaturan::ambil('jam_ganti_hari_penjualan', self::JAM_BAWAAN);

        return preg_match('/^\d{2}:\d{2}$/', $jam) ? $jam : self::JAM_BAWAAN;
    }

    /** Tanggal penjualan bawaan saat ini. */
    public static function tanggal(): Carbon
    {
        $sekarang = now();

        return $sekarang->format('H:i') < self::jamGanti() ? $sekarang->copy()->subDay()->startOfDay() : $sekarang->copy()->startOfDay();
    }

    public static function tanggalTeks(): string
    {
        return self::tanggal()->toDateString();
    }
}
