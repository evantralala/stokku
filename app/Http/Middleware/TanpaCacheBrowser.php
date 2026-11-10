<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman untuk pengguna yang sudah masuk tidak boleh disimpan browser,
 * supaya tombol Kembali selalu memuat angka stok terbaru dari server.
 */
class TanpaCacheBrowser
{
    public function handle(Request $request, Closure $next): Response
    {
        $respons = $next($request);

        if ($request->user() === null || $respons instanceof BinaryFileResponse || $respons instanceof StreamedResponse) {
            return $respons;
        }

        $respons->headers->set('Cache-Control', 'no-store, private');

        return $respons;
    }
}
