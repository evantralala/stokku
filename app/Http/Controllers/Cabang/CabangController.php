<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use Illuminate\Http\Request;

/** Dasar controller Admin Cabang: memastikan akun terhubung ke cabang yang aktif. */
abstract class CabangController extends Controller
{
    protected function lokasi(Request $request): Cabang
    {
        $lokasi = $request->user()->cabang;

        abort_unless(
            $lokasi && ! $lokasi->isPusat() && $lokasi->aktif,
            403,
            'Akun Anda belum terhubung ke cabang yang aktif. Hubungi Admin Utama.'
        );

        return $lokasi;
    }
}
