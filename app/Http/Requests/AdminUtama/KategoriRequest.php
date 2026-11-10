<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\DataMasterRequest;

class KategoriRequest extends DataMasterRequest
{
    public const MAKS_NAMA = 60;

    public const MAKS_URUTAN = 9999;

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:'.self::MAKS_NAMA, $this->unik('kategori')],
            'urutan' => ['required', 'integer', 'between:0,'.self::MAKS_URUTAN],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Kategori dengan nama ini sudah ada. Gunakan nama lain.',
            'urutan.between' => 'Urutan harus angka 0 sampai :max.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nama' => 'nama kategori',
            'urutan' => 'urutan',
        ];
    }
}
