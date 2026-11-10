<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\DataMasterRequest;

class SatuanRequest extends DataMasterRequest
{
    public const MAKS_NAMA = 30;

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:'.self::MAKS_NAMA, $this->unik('satuan')],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Satuan ini sudah ada. Periksa daftar satuan.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nama' => 'nama satuan',
        ];
    }
}
