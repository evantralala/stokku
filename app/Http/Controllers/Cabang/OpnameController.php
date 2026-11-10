<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\KelolaOpname;

/** Stock opname di lokasi Admin Cabang. */
class OpnameController extends CabangController
{
    use KelolaOpname;

    protected string $rute = 'cabang.opname';
}
