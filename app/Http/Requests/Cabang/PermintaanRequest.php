<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\Concerns\IsianAman;
use App\Models\User;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Permintaan bahan dari cabang. Jumlah diisi dalam satuan beli. */
class PermintaanRequest extends FormRequest
{
    use IsianAman;

    public const MAKS_ALASAN = 160;
    public const MAKS_QTY = 9999999;

    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG;
    }

    /** Baris yang benar-benar kosong dibuang; angka dengan koma dirapikan. */
    protected function prepareForValidation(): void
    {
        $baris = collect((array) $this->input('baris', []))
            ->filter(fn ($b) => is_array($b) && (filled($b['bahan_baku_id'] ?? null) || filled($b['qty'] ?? null)))
            ->map(fn (array $b) => [
                'bahan_baku_id' => $b['bahan_baku_id'] ?? null,
                'qty'           => JumlahPintar::isian($b['qty'] ?? null, $b['bahan_baku_id'] ?? null),
                'alasan'        => self::teksSel($b['alasan'] ?? null),
            ])
            ->all();
        $baris = JumlahPintar::gabungBaris($baris);

        $this->merge(['baris' => $baris]);
    }

    public function rules(): array
    {
        return [
            'baris'                 => ['required', 'array'],
            'baris.*.bahan_baku_id' => ['required', 'integer', 'distinct', Rule::exists('bahan_baku', 'id')->where('aktif', true)],
            'baris.*.qty'           => ['required', 'numeric', 'gt:0', 'max:'.self::MAKS_QTY],
            'baris.*.alasan'        => ['nullable', 'string', 'max:'.self::MAKS_ALASAN],
        ];
    }

    public function messages(): array
    {
        return [
            'baris.required'                 => 'Tambahkan minimal satu bahan yang diminta.',
            'baris.*.bahan_baku_id.required' => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.integer'  => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.distinct' => 'Bahan ini sudah ada di baris lain. Gabungkan jumlahnya di satu baris.',
            'baris.*.bahan_baku_id.exists'   => 'Bahan ini sudah tidak aktif atau tidak ditemukan. Pilih bahan lain.',
            'baris.*.qty.required'           => 'Isi jumlah yang diminta.',
            'baris.*.qty.numeric'            => 'Jumlah harus berupa angka, mis. 5 atau 2,5.',
            'baris.*.qty.gt'                 => 'Jumlah harus lebih dari 0.',
            'baris.*.qty.max'                => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'baris.*.alasan.max'             => 'Alasan maksimal :max karakter.',
        ];
    }
}
