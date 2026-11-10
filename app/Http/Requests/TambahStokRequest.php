<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\IsianAman;
use App\Http\Requests\Pusat\BarangMasukRequest;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tambah stok langsung (semua peran). Jumlah dalam satuan beli; keterangan boleh kosong. */
class TambahStokRequest extends FormRequest
{
    use IsianAman;

    public const MAKS_KETERANGAN = 160;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'jumlah'     => JumlahPintar::isian($this->input('jumlah'), $this->input('bahan_baku_id')),
            'keterangan' => $this->teksAtauNull('keterangan'),
        ]);
    }

    public function rules(): array
    {
        return [
            'bahan_baku_id' => ['required', 'integer', Rule::exists('bahan_baku', 'id')->where('aktif', true)],
            'jumlah'        => ['required', 'numeric', 'gt:0', 'max:'.BarangMasukRequest::MAKS_QTY],
            'keterangan'    => ['nullable', 'string', 'max:'.self::MAKS_KETERANGAN],
        ];
    }

    public function messages(): array
    {
        return [
            'bahan_baku_id.required' => 'Pilih bahan dari daftar.',
            'bahan_baku_id.integer'  => 'Pilih bahan dari daftar.',
            'bahan_baku_id.exists'   => 'Bahan ini sudah tidak aktif atau tidak ditemukan.',
            'jumlah.required'        => 'Isi jumlah yang ditambahkan.',
            'jumlah.numeric'         => 'Jumlah harus berupa angka, mis. 5 atau 2,5.',
            'jumlah.gt'              => 'Jumlah harus lebih dari 0.',
            'jumlah.max'             => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'keterangan.max'         => 'Keterangan maksimal :max karakter.',
        ];
    }
}
