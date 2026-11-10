<?php

namespace App\Http\Middleware;

use App\Support\VersiData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setiap permintaan yang menyimpan data (POST, PUT, PATCH, DELETE) dan berhasil mengganti penanda VersiData,
 * supaya halaman pengguna lain ikut diperbarui. Masuk, keluar, ganti password, tandai notifikasi dibaca, dan isian yang
 * ditolak validasi tidak dihitung karena tidak mengubah data yang dilihat orang lain.
 */
class NaikkanVersiData
{
    private const RUTE_DIABAIKAN = ['login.store', 'logout', 'akun.password', 'notifikasi.baca-semua'];

    public function handle(Request $request, Closure $next): Response
    {
        $respons = $next($request);

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            || $respons->getStatusCode() >= 400
            || in_array($request->route()?->getName(), self::RUTE_DIABAIKAN, true)
            || ($request->hasSession() && in_array('errors', (array) $request->session()->get('_flash.new', []), true))) {
            return $respons;
        }

        VersiData::naikkan();

        return $respons;
    }
}
