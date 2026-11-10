<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Concerns\KelolaTerimaBarang;

/** Penerimaan barang di Gudang Pusat: retur yang dikirim cabang. */
class TerimaBarangController extends PusatController
{
    use KelolaTerimaBarang;

    protected string $rute = 'pusat.terima-barang';
}
