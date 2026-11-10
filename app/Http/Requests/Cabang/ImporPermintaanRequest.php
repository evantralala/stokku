<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Models\User;

/** File impor permintaan cabang (Excel atau CSV). */
class ImporPermintaanRequest extends ImporDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG;
    }
}
