<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Concerns\KelolaOpname;

/** Stock opname di lokasi Admin Pusat. */
class OpnameController extends PusatController
{
    use KelolaOpname;

    protected string $rute = 'pusat.opname';
}
