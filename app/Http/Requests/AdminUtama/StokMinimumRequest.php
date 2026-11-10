<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Support\Angka;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Simpan stok minimum satu lokasi. Nilai diisi dalam satuan beli. */
class StokMinimumRequest extends FormRequest
{
    use AksesMenuUtama;

    /** Batas kolom decimal(14,3) dalam satuan dasar; dibulatkan aman untuk isian satuan beli. */
    public const MAKS_NILAI = 99999999;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'minimum' => collect((array) $this->input('minimum', []))
                ->map(fn ($nilai) => Angka::baca($nilai))
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'lokasi_id'  => ['required', 'integer', Rule::exists('cabang', 'id')->where('aktif', true)],
            'minimum'    => ['required', 'array'],
            'minimum.*'  => ['nullable', 'numeric', 'min:0', 'max:'.self::MAKS_NILAI],
        ];
    }

    public function messages(): array
    {
        return [
            'lokasi_id.exists'  => 'Lokasi tidak ditemukan atau sudah tidak aktif. Pilih lokasi lagi.',
            'minimum.required'  => 'Belum ada bahan baku yang bisa diisi.',
            'minimum.*.numeric' => 'Isi dengan angka, mis. 2 atau 2,5.',
            'minimum.*.min'     => 'Stok minimum tidak boleh kurang dari 0.',
            'minimum.*.max'     => 'Angka terlalu besar. Periksa lagi isian ini.',
        ];
    }
}
