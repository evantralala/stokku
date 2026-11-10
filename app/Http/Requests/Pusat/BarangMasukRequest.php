<?php

namespace App\Http\Requests\Pusat;

use App\Http\Requests\Concerns\IsianAman;
use App\Models\User;
use App\Support\JumlahPintar;
use App\Rules\SetelahTutupBuku;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Barang masuk Gudang Pusat. Jumlah diisi dalam satuan beli. */
class BarangMasukRequest extends FormRequest
{
    use IsianAman;

    public const MAKS_CATATAN = 1000;
    public const MAKS_KETERANGAN = 160;
    public const MAKS_QTY = 9999999;

    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_PUSAT;
    }

    /** Baris yang benar-benar kosong dibuang; angka dengan koma dirapikan. */
    protected function prepareForValidation(): void
    {
        $baris = collect((array) $this->input('baris', []))
            ->filter(fn ($b) => is_array($b) && (filled($b['bahan_baku_id'] ?? null) || filled($b['qty'] ?? null)))
            ->map(fn (array $b) => [
                'bahan_baku_id' => $b['bahan_baku_id'] ?? null,
                'qty'           => JumlahPintar::isian($b['qty'] ?? null, $b['bahan_baku_id'] ?? null),
                'keterangan'    => self::teksSel($b['keterangan'] ?? null),
            ])
            ->all();

        $this->merge(['baris' => $baris]);
    }

    public function rules(): array
    {
        return [
            'tanggal'               => ['required', 'date_format:Y-m-d', 'before_or_equal:today', new SetelahTutupBuku()],
            'catatan'               => ['nullable', 'string', 'max:'.self::MAKS_CATATAN],
            'baris'                 => ['required', 'array'],
            'baris.*.bahan_baku_id' => ['required', 'integer', Rule::exists('bahan_baku', 'id')->where('aktif', true)],
            'baris.*.qty'           => ['required', 'numeric', 'gt:0', 'max:'.self::MAKS_QTY],
            'baris.*.keterangan'    => ['nullable', 'string', 'max:'.self::MAKS_KETERANGAN],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.before_or_equal'        => 'Tanggal barang masuk tidak boleh setelah hari ini.',
            'tanggal.date_format'            => 'Pilih tanggal dari kalender.',
            'baris.required'                 => 'Tambahkan minimal satu barang.',
            'baris.*.bahan_baku_id.required' => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.integer'  => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.exists'   => 'Bahan ini sudah tidak aktif atau tidak ditemukan. Pilih bahan lain.',
            'baris.*.qty.required'           => 'Isi jumlah yang diterima.',
            'baris.*.qty.numeric'            => 'Jumlah harus berupa angka, mis. 5 atau 2,5.',
            'baris.*.qty.gt'                 => 'Jumlah harus lebih dari 0.',
            'baris.*.qty.max'                => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'baris.*.keterangan.max'         => 'Keterangan maksimal :max karakter.',
        ];
    }

    public function attributes(): array
    {
        return [
            'tanggal' => 'tanggal',
            'catatan' => 'catatan',
        ];
    }
}
