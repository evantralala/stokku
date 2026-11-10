<?php

namespace App\Exceptions;

use DomainException;

/** Stok lokasi sedang dikunci karena stock opname berjalan. */
class LokasiSedangOpname extends DomainException
{
    public function __construct(public readonly string $namaLokasi)
    {
        parent::__construct("Stok {$namaLokasi} sedang dihitung (stock opname). Coba lagi setelah opname selesai diputuskan Admin Utama.");
    }
}
