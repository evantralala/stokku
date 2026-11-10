<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\PantauStok;
use App\Models\Cabang;
use App\Services\KontrolSistemService;
use Illuminate\Http\Request;

/** Pantauan stok lokasi Admin Cabang sendiri. */
class StokController extends CabangController
{
    use PantauStok;

    protected string $rute = 'cabang.stok';

    protected function lokasiPantauan(Request $request): Cabang
    {
        return $this->lokasi($request);
    }

    protected function aksiIntip(Request $request): ?array
    {
        if (! app(KontrolSistemService::class)->boleh($request->user(), 'permintaan.catat')) {
            return null;
        }

        return ['label' => 'Minta Barang', 'url' => route('cabang.permintaan.buat')];
    }
}
