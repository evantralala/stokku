<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\KelolaBarangKeluar;

/** Barang keluar (rusak, tumpah, retur) di lokasi Admin Cabang. */
class BarangKeluarController extends CabangController
{
    use KelolaBarangKeluar;

    protected string $rute = 'cabang.barang-keluar';
}
