<?php

namespace App\Support;

/**
 * Format angka gaya Indonesia: koma untuk desimal, titik untuk ribuan.
 */
final class Angka
{
    /** 2500.5 -> "2.500,5" (untuk dibaca). Nol di belakang koma dibuang. */
    public static function tampil(float|int|string|null $nilai, int $desimal = 3): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }

        return self::rapikan(number_format((float) $nilai, $desimal, ',', '.'));
    }

    /** 2500.5 -> "2500,5" (untuk nilai awal isian, tanpa pemisah ribuan). */
    public static function isian(float|int|string|null $nilai, int $desimal = 3): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }

        return self::rapikan(number_format((float) $nilai, $desimal, ',', ''));
    }

    /**
     * Ketikan pengguna -> angka standar untuk validasi.
     * "2,5" -> "2.5", "1.250,75" -> "1250.75", "1.5" -> "1.5", " 3 " -> "3".
     * Nilai yang bukan teks dikembalikan apa adanya.
     */
    public static function baca(mixed $teks): mixed
    {
        if (! is_string($teks)) {
            return $teks;
        }

        $teks = str_replace(' ', '', trim($teks));

        if (str_contains($teks, ',')) {
            $teks = str_replace(['.', ','], ['', '.'], $teks);
        }

        return $teks;
    }

    private static function rapikan(string $teks): string
    {
        if (! str_contains($teks, ',')) {
            return $teks;
        }

        return rtrim(rtrim($teks, '0'), ',');
    }
}
