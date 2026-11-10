<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\IsianAman;
use App\Models\User;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Hasil hitung fisik opname (satuan beli). Kosong = belum dihitung. */
class OpnameHitungRequest extends FormRequest
{
    use IsianAman;

    public const AKSI_SIMPAN = 'simpan';
    public const AKSI_KIRIM = 'kirim';

    public const MAKS_CATATAN = 160;
    public const MAKS_QTY = 9999999;

    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_PUSAT, User::ROLE_ADMIN_CABANG], true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'hitung' => collect(JumlahPintar::isianPerDetail((array) $this->input('hitung', []), \App\Models\OpnameDetail::class))
                ->map(fn ($h) => is_array($h) ? [
                    'qty'     => $h['qty'] ?? null,
                    'catatan' => self::teksSel($h['catatan'] ?? null),
                ] : $h)
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'aksi'             => ['required', Rule::in([self::AKSI_SIMPAN, self::AKSI_KIRIM])],
            'hitung'           => ['required', 'array'],
            'hitung.*.qty'     => ['nullable', 'numeric', 'min:0', 'max:'.self::MAKS_QTY],
            'hitung.*.catatan' => ['nullable', 'string', 'max:'.self::MAKS_CATATAN],
        ];
    }

    public function messages(): array
    {
        return [
            'hitung.*.qty.numeric'  => 'Jumlah harus berupa angka, mis. 2 atau 0,5.',
            'hitung.*.qty.min'      => 'Jumlah tidak boleh kurang dari 0.',
            'hitung.*.qty.max'      => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'hitung.*.catatan.max'  => 'Catatan maksimal :max karakter.',
        ];
    }

    public function mauKirim(): bool
    {
        return $this->validated('aksi') === self::AKSI_KIRIM;
    }
}
