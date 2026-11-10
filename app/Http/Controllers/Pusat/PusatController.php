<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use Illuminate\Http\Request;

/** Dasar controller Admin Pusat: memastikan akun terhubung ke Gudang Pusat yang aktif. */
abstract class PusatController extends Controller
{
    protected function lokasi(Request $request): Cabang
    {
        $lokasi = $request->user()->cabang;

        abort_unless(
            $lokasi && $lokasi->isPusat() && $lokasi->aktif,
            403,
            'Akun Anda belum terhubung ke Gudang Pusat yang aktif. Hubungi Admin Utama.'
        );

        return $lokasi;
    }
}
