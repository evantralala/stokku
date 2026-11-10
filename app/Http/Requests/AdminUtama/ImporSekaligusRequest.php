<?php

namespace App\Http\Requests\AdminUtama;

/** File Excel berlembar banyak untuk Impor Sekaligus (lebih besar dari impor per menu). */
class ImporSekaligusRequest extends ImporDataRequest
{
    public const MAKS_KB = 20480;

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:'.self::MAKS_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'File harus Excel (.xlsx) berisi lembar Lokasi, Bahan, Menu, Resep, Stok Minimum, atau Stok.',
            'file.max'   => 'File maksimal 20 MB.',
        ] + parent::messages();
    }
}
