<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\IsianAman;
use App\Services\IsiStokService;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isi stok bahan habis/menipis dari halaman Stok (semua peran). baris[{bahan id}] = [pilih, bahan_baku_id, qty];
 * hanya baris yang dicentang (pilih) yang diproses. Jumlah dalam satuan beli.
 */
class IsiStokRequest extends FormRequest
{
    use IsianAman;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $tambah = $this->modeTambah();

        $baris = collect((array) $this->input('baris', []))
            ->filter(fn ($b) => is_array($b) && filled($b['pilih'] ?? null))
            ->map(fn (array $b) => [
                'bahan_baku_id' => $b['bahan_baku_id'] ?? null,
                'qty'           => JumlahPintar::isian($b['qty'] ?? null, $b['bahan_baku_id'] ?? null),
            ])
            ->all();

        $this->merge([
            'baris'      => $baris,
            'keterangan' => $tambah ? $this->teksAtauNull('keterangan') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'baris'                       => ['required', 'array'],
            'baris.*.bahan_baku_id'       => ['required', 'integer', 'distinct', Rule::exists('bahan_baku', 'id')->where('aktif', true)],
            'baris.*.qty'                 => ['required', 'numeric', 'gt:0', 'max:'.Cabang\PermintaanRequest::MAKS_QTY],
            'keterangan'                  => ['nullable', 'string', 'max:'.TambahStokRequest::MAKS_KETERANGAN],
        ];
    }

    public function messages(): array
    {
        return [
            'baris.required'                             => 'Centang minimal satu bahan yang mau diisi.',
            'baris.*.bahan_baku_id.required'             => 'Bahan tidak ditemukan. Tutup lalu buka lagi.',
            'baris.*.bahan_baku_id.integer'              => 'Bahan tidak ditemukan. Tutup lalu buka lagi.',
            'baris.*.bahan_baku_id.distinct'             => 'Bahan ini muncul dua kali.',
            'baris.*.bahan_baku_id.exists'               => 'Bahan ini sudah tidak aktif. Hapus centangnya.',
            'baris.*.qty.required'                       => 'Isi jumlahnya.',
            'baris.*.qty.numeric'                        => 'Jumlah harus berupa angka, mis. 5 atau 2,5.',
            'baris.*.qty.gt'                             => 'Jumlah harus lebih dari 0.',
            'baris.*.qty.max'                            => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'keterangan.max'                             => 'Keterangan maksimal :max karakter.',
        ];
    }

    public function modeTambah(): bool
    {
        return app(IsiStokService::class)->mode($this->user()) === IsiStokService::MODE_TAMBAH;
    }
}
