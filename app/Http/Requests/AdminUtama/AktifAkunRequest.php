<?php

namespace App\Http\Requests\AdminUtama;

use Illuminate\Foundation\Http\FormRequest;

/** Nyalakan atau matikan akun dari halaman Pengaturan. */
class AktifAkunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminUtama() ?? false;
    }

    public function rules(): array
    {
        return [
            'aktif' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'aktif.*' => 'Status akun tidak valid. Muat ulang halaman lalu coba lagi.',
        ];
    }
}
