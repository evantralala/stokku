<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Concerns\KelolaBarangKeluar;

/** Barang keluar (rusak, tumpah, retur) di lokasi Admin Pusat. */
class BarangKeluarController extends PusatController
{
    use KelolaBarangKeluar;

    protected string $rute = 'pusat.barang-keluar';
}
