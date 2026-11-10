<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Http\Requests\Concerns\IsianAman;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pindah Gudang Pusat: data lokasi baru dan password Admin Utama sebagai pengaman. */
class PindahGudangPusatRequest extends FormRequest
{
    use IsianAman, AksesMenuUtama;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('kode')) {
            $this->merge(['kode' => Str::upper(preg_replace('/\s+/', '', $this->teks('kode')))]);
        }
    }

    public function rules(): array
    {
        return [
            'nama'              => ['required', 'string', 'max:'.LokasiRequest::MAKS['nama'], Rule::unique('cabang', 'nama')],
            'kode'              => ['required', 'string', 'max:'.LokasiRequest::MAKS['kode'], 'regex:/^[A-Z0-9-]+$/', Rule::unique('cabang', 'kode')],
            'alamat'            => ['nullable', 'string', 'max:'.LokasiRequest::MAKS['alamat']],
            'password'          => ['bail', 'required', 'string', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode.regex'                => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung (-). Contoh: GP2.',
            'kode.unique'               => 'Kode ini sudah dipakai lokasi lain. Gunakan kode lain.',
            'nama.unique'               => 'Nama lokasi ini sudah terdaftar. Gunakan nama lain.',
            'password.current_password' => 'Password salah.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nama'              => 'nama gudang baru',
            'kode'              => 'kode gudang baru',
            'alamat'            => 'alamat',
            'password'          => 'password',
        ];
    }
}
