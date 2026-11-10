<?php

namespace App\Http\Controllers;

use App\Services\IdentitasUsahaService;
use App\Support\Identitas;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Logo unggahan dan manifest aplikasi HP (PWA), dibaca tanpa login supaya tampil juga di halaman masuk. */
class IdentitasController extends Controller
{
    private const CACHE_DETIK = 2592000;

    public function berkas(IdentitasUsahaService $identitas, string $berkas): BinaryFileResponse
    {
        $jalur = $identitas->jalur($berkas);
        abort_if($jalur === null, 404);

        return response()->file($jalur, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age='.self::CACHE_DETIK.', immutable',
        ]);
    }

    public function manifest(): JsonResponse
    {
        $ikon = fn (string $berkas, string $ukuran, string $tujuan) => [
            'src' => Identitas::logo($berkas), 'sizes' => $ukuran, 'type' => 'image/png', 'purpose' => $tujuan,
        ];

        return response()->json([
            'name'             => Identitas::aplikasi(),
            'short_name'       => Identitas::singkat(),
            'description'      => 'Manajemen stok gudang pusat dan cabang '.Identitas::usaha().'.',
            'lang'             => 'id',
            'start_url'        => url('/'),
            'scope'            => url('/').'/',
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#0a1020',
            'theme_color'      => '#0a1020',
            'icons'            => [
                $ikon('ikon-192.png', '192x192', 'any'),
                $ikon('ikon-512.png', '512x512', 'any'),
                $ikon('ikon-maskable-512.png', '512x512', 'maskable'),
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
