<?php

namespace App\Http\Requests;

use App\Http\Requests\AdminUtama\ImporDataRequest;

/** File pembaruan stok lewat Excel (semua peran, lokasi sendiri atau lokasi pilihan Admin Utama). */
class PerbaruiStokExcelRequest extends ImporDataRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
