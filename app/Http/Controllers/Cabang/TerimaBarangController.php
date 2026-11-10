<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\KelolaTerimaBarang;

/** Penerimaan kiriman di cabang: dari Gudang Pusat atau dari cabang lain. */
class TerimaBarangController extends CabangController
{
    use KelolaTerimaBarang;

    protected string $rute = 'cabang.terima-barang';
}
