<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Models\User;

/** File Excel/CSV penjualan harian yang diunggah Admin Cabang untuk cabangnya sendiri. */
class ImporPenjualanRequest extends ImporDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG;
    }
}
