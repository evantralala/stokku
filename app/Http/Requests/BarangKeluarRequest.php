<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\IsianAman;
use App\Models\User;
use App\Support\JumlahPintar;
use App\Rules\SetelahTutupBuku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Barang rusak dari Gudang Pusat atau cabang: bahan, jumlah (satuan beli), dan alasan per barang. */
class BarangKeluarRequest extends FormRequest
{
    use IsianAman;

    public const MAKS_ALASAN = 160;
    public const MAKS_QTY = 9999999;

    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_PUSAT, User::ROLE_ADMIN_CABANG], true);
    }

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

        $this->merge(['baris' => $baris]);
    }

    public function rules(): array
    {
        return [
            'tanggal'               => ['required', 'date_format:Y-m-d', 'before_or_equal:today', new SetelahTutupBuku()],
            'baris'                 => ['required', 'array'],
            'baris.*.bahan_baku_id' => ['required', 'integer', 'distinct', Rule::exists('bahan_baku', 'id')->where('aktif', true)],
            'baris.*.qty'           => ['required', 'numeric', 'gt:0', 'max:'.self::MAKS_QTY],
            'baris.*.alasan'        => ['required', 'string', 'max:'.self::MAKS_ALASAN],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.before_or_equal'        => 'Tanggal tidak boleh setelah hari ini.',
            'tanggal.date_format'            => 'Pilih tanggal dari kalender.',
            'baris.required'                 => 'Tambahkan minimal satu barang.',
            'baris.*.bahan_baku_id.required' => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.integer'  => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.distinct' => 'Bahan ini sudah ada di baris lain. Gabungkan jumlahnya di satu baris.',
            'baris.*.bahan_baku_id.exists'   => 'Bahan ini sudah tidak aktif atau tidak ditemukan. Pilih bahan lain.',
            'baris.*.qty.required'           => 'Isi jumlah yang keluar.',
            'baris.*.qty.numeric'            => 'Jumlah harus berupa angka, mis. 0,5 atau 2.',
            'baris.*.qty.gt'                 => 'Jumlah harus lebih dari 0.',
            'baris.*.qty.max'                => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'baris.*.alasan.required'        => 'Tulis alasannya, mis. kemasan bocor.',
            'baris.*.alasan.max'             => 'Alasan maksimal :max karakter.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tanggal' => 'tanggal',
        ];
    }
}
