<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\Concerns\IsianAman;
use App\Http\Requests\Pusat\KirimanRequest;
use App\Models\User;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Kiriman dari cabang ke cabang lain atau retur ke Gudang Pusat. Jumlah diisi dalam satuan beli. */
class KirimBarangRequest extends FormRequest
{
    use IsianAman;

    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG;
    }

    /** Baris yang benar-benar kosong dibuang; angka dengan koma dirapikan. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'baris' => JumlahPintar::gabungBaris(collect((array) $this->input('baris', []))
                ->filter(fn ($b) => is_array($b) && (filled($b['bahan_baku_id'] ?? null) || filled($b['qty'] ?? null)))
                ->map(fn (array $b) => ['bahan_baku_id' => $b['bahan_baku_id'] ?? null, 'qty' => JumlahPintar::isian($b['qty'] ?? null, $b['bahan_baku_id'] ?? null)])
                ->all()),
            'catatan' => $this->teksAtauNull('catatan'),
        ]);
    }

    public function rules(): array
    {
        return [
            'ke_cabang_id'          => [
                'required', 'integer',
                Rule::exists('cabang', 'id')->where('aktif', true)->whereNot('id', (int) $this->user()?->cabang_id),
            ],
            'baris'                 => ['required', 'array'],
            'baris.*.bahan_baku_id' => ['required', 'integer', 'distinct', Rule::exists('bahan_baku', 'id')->where('aktif', true)],
            'baris.*.qty'           => ['required', 'numeric', 'gt:0', 'max:'.PermintaanRequest::MAKS_QTY],
            'catatan'               => ['nullable', 'string', 'max:'.KirimanRequest::MAKS_CATATAN],
        ];
    }

    public function messages(): array
    {
        return [
            'ke_cabang_id.required'          => 'Pilih tujuan kiriman.',
            'ke_cabang_id.integer'           => 'Pilih tujuan kiriman.',
            'ke_cabang_id.exists'            => 'Tujuan tidak aktif atau sama dengan cabang ini. Pilih tujuan lain.',
            'baris.required'                 => 'Tambahkan minimal satu bahan yang dikirim.',
            'baris.*.bahan_baku_id.required' => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.integer'  => 'Pilih bahan dari daftar.',
            'baris.*.bahan_baku_id.distinct' => 'Bahan ini sudah ada di baris lain. Gabungkan jumlahnya di satu baris.',
            'baris.*.bahan_baku_id.exists'   => 'Bahan ini sudah tidak aktif atau tidak ditemukan. Pilih bahan lain.',
            'baris.*.qty.required'           => 'Isi jumlah yang dikirim.',
            'baris.*.qty.numeric'            => 'Jumlah harus berupa angka, mis. 5 atau 2,5.',
            'baris.*.qty.gt'                 => 'Jumlah harus lebih dari 0.',
            'baris.*.qty.max'                => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'catatan.max'                    => 'Catatan maksimal :max karakter.',
        ];
    }

    /** @return array<int, float> [bahan_baku_id => qty satuan beli] */
    public function jumlah(): array
    {
        return collect($this->validated('baris'))->mapWithKeys(fn (array $b) => [(int) $b['bahan_baku_id'] => (float) $b['qty']])->all();
    }
}
