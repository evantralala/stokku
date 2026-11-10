<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\Concerns\IsianAman;
use App\Models\KirimanDetail;
use App\Models\User;
use App\Support\JumlahPintar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Terima kiriman (Admin Cabang, atau Admin Pusat untuk retur): "sesuai" (semua barang diterima sebanyak yang dikirim), atau isi per barang:
 * jumlah layak pakai (satuan beli), kondisi, dan catatan. Tanpa pilihan kesesuaian dianggap isi per barang.
 */
class TerimaKirimanRequest extends FormRequest
{
    use IsianAman;

    public const MAKS_CATATAN = 160;
    public const MAKS_QTY = 9999999;

    public const SESUAI = 'sesuai';
    public const TIDAK_SESUAI = 'tidak_sesuai';

    public const LABEL_KESESUAIAN = [
        self::SESUAI       => 'Sesuai Semua',
        self::TIDAK_SESUAI => 'Ada yang Beda',
    ];

    public function sesuai(): bool
    {
        return $this->input('kesesuaian') === self::SESUAI;
    }

    public function authorize(): bool
    {
        return in_array($this->user()?->role, [User::ROLE_ADMIN_CABANG, User::ROLE_ADMIN_PUSAT], true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'terima' => collect(JumlahPintar::isianPerDetail((array) $this->input('terima', []), \App\Models\KirimanDetail::class))
                ->map(fn ($t) => is_array($t) ? [
                    'qty'     => $t['qty'] ?? null,
                    'kondisi' => $t['kondisi'] ?? null,
                    'catatan' => self::teksSel($t['catatan'] ?? null),
                ] : $t)
                ->all(),
        ]);
    }

    public function rules(): array
    {
        $perBarang = 'exclude_if:kesesuaian,'.self::SESUAI;

        return [
            'kesesuaian'       => ['nullable', Rule::in(array_keys(self::LABEL_KESESUAIAN))],
            'terima'           => [$perBarang, 'required', 'array'],
            'terima.*.kondisi' => [$perBarang, 'required', Rule::in(array_keys(KirimanDetail::LABEL_KONDISI))],
            'terima.*.qty'     => [
                $perBarang,
                'exclude_if:terima.*.kondisi,'.KirimanDetail::KONDISI_TIDAK_ADA,
                'required', 'numeric', 'min:0', 'max:'.self::MAKS_QTY,
            ],
            'terima.*.catatan' => [
                $perBarang,
                'required_if:terima.*.kondisi,'.KirimanDetail::KONDISI_RUSAK.','.KirimanDetail::KONDISI_TIDAK_ADA,
                'nullable', 'string', 'max:'.self::MAKS_CATATAN,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'kesesuaian.in'             => 'Pilih Sesuai semua atau Ada yang beda.',
            'terima.required'           => 'Tidak ada barang yang bisa dicatat.',
            'terima.*.kondisi.required' => 'Pilih kondisi barang.',
            'terima.*.kondisi.in'       => 'Pilih kondisi dari daftar.',
            'terima.*.qty.required'     => 'Hitung dan isi jumlah yang diterima. Isi 0 bila tidak ada yang layak pakai.',
            'terima.*.qty.numeric'      => 'Jumlah harus berupa angka, mis. 2 atau 2,5.',
            'terima.*.qty.min'          => 'Jumlah tidak boleh kurang dari 0.',
            'terima.*.qty.max'          => 'Jumlah terlalu besar. Periksa lagi angkanya.',
            'terima.*.catatan.required_if' => 'Jelaskan apa yang rusak atau tidak ada, mis. 2 bungkus sobek.',
            'terima.*.catatan.max'      => 'Catatan maksimal :max karakter.',
        ];
    }
}
