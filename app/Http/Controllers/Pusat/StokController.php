<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Concerns\PantauStok;
use App\Models\Cabang;
use Illuminate\Http\Request;

/** Pantauan stok lokasi Admin Pusat sendiri. */
class StokController extends PusatController
{
    use PantauStok;

    protected string $rute = 'pusat.stok';

    protected function lokasiPantauan(Request $request): Cabang
    {
        return $this->lokasi($request);
    }
}
