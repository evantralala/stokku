<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Http\Requests\Concerns\IsianAman;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Kosongkan stok bahan terpilih di satu lokasi (Admin Utama): bahan dicentang dan alasan; ketik KOSONGKAN bila lebih dari satu bahan. */
class KosongkanStokRequest extends FormRequest
{
    use IsianAman, AksesMenuUtama;

    public const KATA_KONFIRMASI = 'KOSONGKAN';

    public const MAKS_ALASAN = 120;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'alasan'     => $this->teksAtauNull('alasan'),
            'konfirmasi' => mb_strtoupper($this->teks('konfirmasi')),
        ]);
    }

    public function rules(): array
    {
        return [
            'bahan'      => ['required', 'array'],
            'bahan.*'    => ['integer', 'exists:bahan_baku,id'],
            'alasan'     => ['required', 'string', 'max:'.self::MAKS_ALASAN],
            'konfirmasi' => count((array) $this->input('bahan')) > 1
                ? ['required', Rule::in([self::KATA_KONFIRMASI])]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'bahan.required'    => 'Centang minimal satu bahan.',
            'alasan.required'   => 'Tulis alasannya, mis. bahan kedaluwarsa dibuang.',
            'alasan.max'        => 'Alasan maksimal '.self::MAKS_ALASAN.' karakter.',
            'konfirmasi.required' => 'Ketik '.self::KATA_KONFIRMASI.' untuk melanjutkan.',
            'konfirmasi.in'     => 'Ketik '.self::KATA_KONFIRMASI.' persis untuk melanjutkan.',
        ];
    }
}
