<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Http\Requests\Concerns\IsianAman;
use App\Support\Angka;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Satu versi resep: tanggal berlaku dan daftar bahan beserta takarannya (satuan dasar). */
class ResepRequest extends FormRequest
{
    use IsianAman, AksesMenuUtama;


    /** Batas kolom decimal(14,3). */
    public const MAKS_JUMLAH = 99999999999;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    /** Baris yang benar-benar kosong (tanpa bahan dan takaran) dibuang; angka dengan koma dirapikan. */
    protected function prepareForValidation(): void
    {
        $baris = collect((array) $this->input('baris', []))
            ->filter(fn ($b) => is_array($b) && (filled($b['bahan_baku_id'] ?? null) || filled($b['jumlah'] ?? null)))
            ->map(fn (array $b) => [
                'bahan_baku_id' => $b['bahan_baku_id'] ?? null,
                'jumlah'        => Angka::baca($b['jumlah'] ?? null),
            ])
            ->all();

        $this->merge(['baris' => $baris]);
    }

    public function rules(): array
    {
        return [
            'berlaku_sejak'         => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'baris'                 => ['required', 'array'],
            'baris.*.bahan_baku_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('bahan_baku', 'id')->where('aktif', true),
            ],
            'baris.*.jumlah'  => ['required', 'numeric', 'gt:0', 'max:'.self::MAKS_JUMLAH],
        ];
    }

    public function messages(): array
    {
        return [
            'berlaku_sejak.after_or_equal'   => 'Tanggal berlaku tidak boleh sebelum hari ini. Resep yang sudah lewat tidak bisa diubah supaya laporan lama tetap benar.',
            'berlaku_sejak.date_format'      => 'Pilih tanggal berlaku dari kalender.',
            'baris.required'                 => 'Tambahkan minimal satu bahan ke resep.',
            'baris.*.bahan_baku_id.required' => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.integer'  => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.distinct' => 'Bahan ini sudah ada di baris lain. Gabungkan takarannya di satu baris.',
            'baris.*.bahan_baku_id.exists'   => 'Bahan ini sudah tidak aktif atau tidak ditemukan. Pilih bahan lain.',
            'baris.*.jumlah.required'        => 'Isi takaran bahan ini.',
            'baris.*.jumlah.numeric'         => 'Takaran harus berupa angka, mis. 150 atau 2,5.',
            'baris.*.jumlah.gt'              => 'Takaran harus lebih dari 0.',
            'baris.*.jumlah.max'             => 'Takaran terlalu besar. Periksa lagi angkanya.',
        ];
    }

    public function attributes(): array
    {
        return [
            'berlaku_sejak' => 'tanggal berlaku',
        ];
    }
}
