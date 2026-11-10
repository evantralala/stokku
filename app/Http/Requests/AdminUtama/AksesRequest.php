<?php

namespace App\Http\Requests\AdminUtama;

use Illuminate\Foundation\Http\FormRequest;

/** Hak akses satu peran: akses[menu][aksi] = 0/1. Menu atau aksi yang dikunci diabaikan di KontrolSistemService. */
class AksesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminUtama() ?? false;
    }

    public function rules(): array
    {
        return [
            'akses'     => ['nullable', 'array'],
            'akses.*'   => ['array'],
            'akses.*.*' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'akses.*' => 'Isian hak akses tidak valid. Muat ulang halaman lalu coba lagi.',
        ];
    }
}
