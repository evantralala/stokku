<?php

namespace App\Http\Requests\Pusat;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Permintaan yang dicentang untuk dikirim seluruh sisanya sekaligus. */
class KirimBanyakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_PUSAT, User::ROLE_ADMIN_UTAMA], true);
    }

    /** @return list<int> id yang dicentang */
    public function dipilih(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('permintaan') ?? [])));
    }

    public function rules(): array
    {
        return [
            'permintaan'   => ['nullable', 'array'],
            'permintaan.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'permintaan.*' => 'Pilihan permintaan tidak dikenal. Muat ulang halaman.',
        ];
    }
}
