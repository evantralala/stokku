<?php

namespace App\Http\Requests\Pusat;

use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Models\User;

/** File impor barang masuk Gudang Pusat (Excel atau CSV dari templat). */
class ImporBarangMasukRequest extends ImporDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_PUSAT;
    }
}
