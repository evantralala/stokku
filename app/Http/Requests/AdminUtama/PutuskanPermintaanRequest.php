<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Http\Requests\Concerns\IsianAman;
use App\Services\PersetujuanService;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Keputusan Admin Utama per barang permintaan. Jumlah disetujui diisi dalam satuan beli. */
class PutuskanPermintaanRequest extends FormRequest
{
    use IsianAman, AksesMenuUtama;

    public const MAKS_CATATAN = 160;
    public const MAKS_QTY = 9999999;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'keputusan' => collect(JumlahPintar::isianPerDetail((array) $this->input('keputusan', []), \App\Models\PermintaanDetail::class))
                ->map(fn ($k) => is_array($k) ? [
                    'aksi'    => $k['aksi'] ?? null,
                    'qty'     => $k['qty'] ?? null,
                    'catatan' => self::teksSel($k['catatan'] ?? null),
                ] : $k)
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'keputusan'           => ['required', 'array'],
            'keputusan.*.aksi'    => ['required', Rule::in([
                PersetujuanService::AKSI_SETUJUI,
                PersetujuanService::AKSI_TOLAK,
                PersetujuanService::AKSI_NANTI,
            ])],
            'keputusan.*.qty'     => ['exclude_unless:keputusan.*.aksi,'.PersetujuanService::AKSI_SETUJUI, 'required', 'numeric', 'gt:0', 'max:'.self::MAKS_QTY],
            'keputusan.*.catatan' => ['nullable', 'string', 'max:'.self::MAKS_CATATAN],
        ];
    }

    public function messages(): array
    {
        return [
            'keputusan.required'          => 'Belum ada barang yang bisa diputuskan.',
            'keputusan.*.aksi.required'   => 'Pilih keputusan untuk barang ini.',
            'keputusan.*.aksi.in'         => 'Pilih Setujui, Tolak, atau Nanti.',
            'keputusan.*.qty.required'    => 'Isi jumlah yang disetujui.',
            'keputusan.*.qty.numeric'     => 'Jumlah harus berupa angka, mis. 2 atau 2,5.',
            'keputusan.*.qty.gt'          => 'Jumlah harus lebih dari 0. Pilih Tolak bila tidak disetujui.',
            'keputusan.*.qty.max'         => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'keputusan.*.catatan.max'     => 'Catatan maksimal :max karakter.',
        ];
    }
}
