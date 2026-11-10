<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use Illuminate\Foundation\Http\FormRequest;

/** File Excel atau CSV dari templat untuk diimpor (data master, barang masuk, perbarui stok). */
class ImporDataRequest extends FormRequest
{
    use AksesMenuUtama;

    public const MAKS_KB = 5120;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.self::MAKS_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Pilih file dulu.',
            'file.file'     => 'File gagal diunggah. Coba lagi.',
            'file.mimes'    => 'File harus Excel (.xlsx) atau CSV.',
            'file.max'      => 'File maksimal 5 MB.',
        ];
    }
}
