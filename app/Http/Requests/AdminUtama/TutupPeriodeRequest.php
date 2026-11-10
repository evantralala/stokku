<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\IsianAman;
use App\Services\PeriodeBukuService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tutup buku dari Pengaturan: nama periode, ketik TUTUP BUKU, dan password sendiri. */
class TutupPeriodeRequest extends FormRequest
{
    use IsianAman;

    public const MAKS_NAMA = 60;

    public function authorize(): bool
    {
        return $this->user()?->isAdminUtama() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['konfirmasi' => mb_strtoupper($this->teks('konfirmasi'))]);
    }

    public function rules(): array
    {
        return [
            'nama'       => ['required', 'string', 'max:'.self::MAKS_NAMA],
            'konfirmasi' => ['required', 'string', Rule::in([PeriodeBukuService::KATA_KONFIRMASI])],
            'password'   => ['bail', 'required', 'string', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.required'             => 'Isi nama periode, mis. Oktober 2026.',
            'nama.max'                  => 'Nama periode maksimal :max karakter.',
            'konfirmasi.required'       => 'Ketik '.PeriodeBukuService::KATA_KONFIRMASI.' untuk memastikan.',
            'konfirmasi.in'             => 'Ketik '.PeriodeBukuService::KATA_KONFIRMASI.' persis.',
            'password.required'         => 'Masukkan password Anda.',
            'password.current_password' => 'Password salah.',
        ];
    }
}
