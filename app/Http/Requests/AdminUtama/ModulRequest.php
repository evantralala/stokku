<?php

namespace App\Http\Requests\AdminUtama;

use Illuminate\Foundation\Http\FormRequest;

/** Nyalakan atau matikan satu modul dari halaman Pengaturan. */
class ModulRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminUtama() ?? false;
    }

    public function rules(): array
    {
        return [
            'nyala' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nyala.*' => 'Status modul tidak valid. Muat ulang halaman lalu coba lagi.',
        ];
    }
}
