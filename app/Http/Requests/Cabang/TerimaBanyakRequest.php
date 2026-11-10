<?php

namespace App\Http\Requests\Cabang;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Kiriman yang dicentang untuk diterima sekaligus dengan pernyataan "sesuai semua". */
class TerimaBanyakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_CABANG, User::ROLE_ADMIN_PUSAT], true);
    }

    /** @return list<int> id yang dicentang */
    public function dipilih(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('kiriman') ?? [])));
    }

    public function rules(): array
    {
        return [
            'kiriman'   => ['nullable', 'array'],
            'kiriman.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'kiriman.*' => 'Pilihan kiriman tidak dikenal. Muat ulang halaman.',
        ];
    }
}
