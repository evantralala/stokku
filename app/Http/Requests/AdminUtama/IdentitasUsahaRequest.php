<?php

namespace App\Http\Requests\AdminUtama;

use App\Services\IdentitasUsahaService;
use Illuminate\Foundation\Http\FormRequest;

/** Identitas usaha di Pengaturan: nama usaha, nama aplikasi, nama singkat, kota, dan logo. */
class IdentitasUsahaRequest extends FormRequest
{
    public const MAKS_NAMA = 60;
    public const MAKS_SINGKAT = 12;
    public const MAKS_KOTA = 40;

    public function authorize(): bool
    {
        return $this->user()?->isAdminUtama() ?? false;
    }

    public function rules(): array
    {
        return [
            'nama_usaha'    => ['required', 'string', 'max:'.self::MAKS_NAMA],
            'nama_aplikasi' => ['required', 'string', 'max:'.self::MAKS_NAMA],
            'nama_singkat'  => ['required', 'string', 'max:'.self::MAKS_SINGKAT],
            'kota'          => ['nullable', 'string', 'max:'.self::MAKS_KOTA],
            'logo'          => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.IdentitasUsahaService::MAKS_LOGO_KB],
            'logo_bawaan'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_usaha.required'    => 'Isi nama usaha.',
            'nama_aplikasi.required' => 'Isi nama aplikasi.',
            'nama_singkat.required'  => 'Isi nama singkat untuk ikon di HP.',
            'nama_singkat.max'       => 'Nama singkat maksimal :max huruf supaya muat di bawah ikon HP.',
            '*.max'                  => 'Maksimal :max karakter.',
            'logo.image'             => 'Logo harus berupa gambar.',
            'logo.mimes'             => 'Logo harus PNG, JPG, atau WebP.',
            'logo.max'               => 'Ukuran logo maksimal '.(IdentitasUsahaService::MAKS_LOGO_KB / 1024).' MB.',
        ];
    }
}
