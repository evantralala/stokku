<?php

namespace App\Http\Controllers;

use App\Support\VersiData;
use Illuminate\Http\JsonResponse;

/**
 * Penanda perubahan data untuk pembaruan otomatis halaman (resources/js/segar.js).
 * Dipanggil sangat sering, jadi didaftarkan tanpa sesi dan tanpa kueri selain satu baca cache.
 */
class VersiDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['versi' => VersiData::sekarang()])
            ->header('Cache-Control', 'no-store, private');
    }
}
