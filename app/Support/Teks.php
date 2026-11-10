<?php

namespace App\Support;

/**
 * Penulisan judul dan label: setiap kata diawali huruf besar ("Kategori Menu", "Gudang Pusat"),
 * kecuali kata depan dan kata hubung di tengah judul ("Kiriman ke Cabang", "Menu & Resep").
 * Kata yang sudah memuat huruf besar (PDF, Excel, Admin Utama) dibiarkan.
 */
final class Teks
{
    private const KATA_KECIL = [
        'dan', 'atau', 'di', 'ke', 'dari', 'untuk', 'yang', 'dengan', 'per', 'pada', 'oleh', 'tanpa', 'sebagai',
        'atas', 'bila', 'sampai', 'hingga', 'dalam', 'kepada', 'bagi', 'tentang', '&',
    ];

    public static function judul(?string $teks): string
    {
        $teks = (string) $teks;
        $bagian = preg_split('/(\s+)/u', $teks, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pertama = true;

        foreach ($bagian as $i => $kata) {
            if (trim($kata) === '') {
                continue;
            }

            if (! $pertama && in_array(mb_strtolower($kata), self::KATA_KECIL, true) && $kata === mb_strtolower($kata)) {
                continue;
            }

            if (preg_match('/^([^\p{L}\p{N}]*)(\p{Ll})(.*)$/u', $kata, $m) === 1 && ! str_ends_with($m[1], '.') && ! str_starts_with($m[1], '&')) {
                $bagian[$i] = $m[1].mb_strtoupper($m[2]).$m[3];
            }

            $pertama = false;
        }

        return implode('', $bagian);
    }
}
