<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alamat yang diutak-atik (mis. ?cari[]=a atau ?lokasi[x]=1) tidak boleh membuat halaman galat.
 * Isian kueri berbentuk daftar dibuang, kecuali yang memang daftar (KUNCI_DAFTAR), dan isi daftar itu
 * hanya boleh nilai tunggal. Halaman lalu memakai nilai bawaannya seperti saat isian itu tidak ada.
 */
class RapikanKueri
{
    /** Kueri yang memang dikirim sebagai daftar, mis. ?bahan[]=1&bahan[]=2. */
    public const KUNCI_DAFTAR = ['bahan'];

    public function handle(Request $request, Closure $next): Response
    {
        $kueri = $request->query->all();
        $rapi = [];

        foreach ($kueri as $kunci => $nilai) {
            if (! is_array($nilai)) {
                $rapi[$kunci] = $nilai;
            } elseif (in_array($kunci, self::KUNCI_DAFTAR, true)) {
                $rapi[$kunci] = array_values(array_filter($nilai, fn ($v) => is_scalar($v)));
            }
        }

        if ($rapi !== $kueri) {
            $request->query->replace($rapi);
            $request->merge([]);
        }

        return $next($request);
    }
}
