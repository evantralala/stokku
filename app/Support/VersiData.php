<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Penanda perubahan data. Berganti setiap kali ada data yang disimpan (form, persetujuan, perintah terjadwal).
 * Halaman yang terbuka menanyakan penanda ini setiap beberapa detik dan baru memuat ulang isinya bila penandanya berganti,
 * sehingga perubahan dari pengguna lain tampil dalam hitungan detik tanpa membebani server.
 */
final class VersiData
{
    public const KUNCI = 'versi-data';

    public static function sekarang(): string
    {
        return (string) Cache::rememberForever(self::KUNCI, fn () => Str::random(16));
    }

    public static function naikkan(): void
    {
        Cache::forever(self::KUNCI, Str::random(16));
    }
}
