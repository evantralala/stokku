<?php

namespace App\Support;

use App\Services\IdentitasUsahaService;

/** Pintasan untuk tampilan: nama dan logo usaha dari IdentitasUsahaService (Pengaturan > Identitas Usaha). */
final class Identitas
{
    public static function usaha(): string
    {
        return self::layanan()->data()['nama_usaha'];
    }

    public static function aplikasi(): string
    {
        return self::layanan()->data()['nama_aplikasi'];
    }

    public static function singkat(): string
    {
        return self::layanan()->data()['nama_singkat'];
    }

    public static function kota(): string
    {
        return self::layanan()->data()['kota'];
    }

    /** Alamat logo atau ikon, mis. 'logo.png', 'logo-kecil.png', 'apple-touch-icon.png'. */
    public static function logo(string $berkas = 'logo.png'): string
    {
        return self::layanan()->url($berkas);
    }

    /** Kelas tambahan untuk <img> logo: logo unggahan tampil apa adanya, tanpa bingkai bayangan logo bawaan. */
    public static function kelasLogo(): string
    {
        return self::layanan()->adaLogoUnggahan() ? 'logo-unggahan' : '';
    }

    private static function layanan(): IdentitasUsahaService
    {
        return app(IdentitasUsahaService::class);
    }
}
