<?php

namespace App\Http\Requests\AdminUtama;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Services\PersetujuanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Keputusan Admin Utama atas satu dokumen (barang masuk, barang keluar). Catatan wajib bila tidak disetujui. */
class PutuskanDokumenRequest extends FormRequest
{
    use AksesMenuUtama;

    public const MAKS_CATATAN = 160;

    public function authorize(): bool
    {
        return $this->bolehMenuUtama();
    }

    public function rules(): array
    {
        return [
            'aksi'    => ['required', Rule::in([
                PersetujuanService::AKSI_SETUJUI,
                PersetujuanService::AKSI_REVISI,
                PersetujuanService::AKSI_TOLAK,
            ])],
            'catatan' => [
                'nullable', 'string', 'max:'.self::MAKS_CATATAN,
                Rule::requiredIf(fn () => $this->input('aksi') !== PersetujuanService::AKSI_SETUJUI),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'aksi.required'    => 'Pilih tindakan.',
            'aksi.in'          => 'Pilih tindakan dari daftar.',
            'catatan.required' => 'Tulis alasannya supaya pencatat tahu apa yang perlu diperbaiki.',
            'catatan.max'      => 'Catatan maksimal :max karakter.',
        ];
    }
}
