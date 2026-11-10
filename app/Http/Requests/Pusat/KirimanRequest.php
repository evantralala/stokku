<?php

namespace App\Http\Requests\Pusat;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Kirim barang yang disetujui: jumlah mengikuti persetujuan dan stok gudang (KirimanService::kirimYangAda), hanya catatan yang diisi. */
class KirimanRequest extends FormRequest
{
    public const MAKS_CATATAN = 1000;

    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_PUSAT, User::ROLE_ADMIN_UTAMA], true);
    }

    public function rules(): array
    {
        return [
            'catatan_pusat' => ['nullable', 'string', 'max:'.self::MAKS_CATATAN],
        ];
    }

    public function attributes(): array
    {
        return [
            'catatan_pusat' => 'catatan',
        ];
    }
}
