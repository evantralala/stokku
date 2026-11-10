<?php

namespace App\Http\Requests;

use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Models\User;

/** File impor stock opname (Excel atau CSV) dari Admin Pusat atau Admin Cabang. */
class ImporOpnameRequest extends ImporDataRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_PUSAT, User::ROLE_ADMIN_CABANG], true);
    }
}
