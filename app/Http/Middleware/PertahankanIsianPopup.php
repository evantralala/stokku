<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form di popup gagal validasi: halaman dimuat ulang, lalu popup mengambil ulang isinya
 * (request dengan header X-Popup). Pesan error dan isian lama dipertahankan satu request lagi
 * supaya ikut tampil di popup itu. Penanda `_popup` ditambahkan resources/js/popup.js.
 */
class PertahankanIsianPopup
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->headers->has('X-Popup') && $request->hasSession()) {
            $sesi = $request->session();
            $popup = $sesi->getOldInput('_popup');

            if (is_string($popup) && str_starts_with($popup, '/') && $sesi->has('errors')) {
                $sesi->reflash();
            }
        }

        return $next($request);
    }
}
