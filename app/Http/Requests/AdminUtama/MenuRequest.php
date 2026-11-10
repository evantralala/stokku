<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AturanFoto;
use App\Http\Requests\DataMasterRequest;
use Illuminate\Validation\Rule;

class MenuRequest extends DataMasterRequest
{
    use AturanFoto;

    public const MAKS = [
        'nama' => 100,
    ];

    public function rules(): array
    {
        $kategoriSekarang = $this->dataYangDiubah()?->getAttribute('kategori_menu_id');

        return [
            'nama' => ['required', 'string', 'max:'.self::MAKS['nama'], $this->unik('menu')],
            'kategori_menu_id' => [
                'required', 'integer',
                Rule::exists('kategori_menu', 'id')->where(function ($q) use ($kategoriSekarang) {
                    $q->where('aktif', true)->when($kategoriSekarang, fn ($q) => $q->orWhere('id', $kategoriSekarang));
                }),
            ],
        ] + $this->aturanFoto();
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Menu dengan nama ini sudah ada. Periksa daftar menu.',
        ] + $this->pesanFoto();
    }

    public function attributes(): array
    {
        return [
            'nama'             => 'nama menu',
            'kategori_menu_id' => 'kategori menu',
        ];
    }

    /** Kolom milik tabel menu. */
    public function dataMenu(): array
    {
        return $this->dataTanpaFoto();
    }
}
