<?php

namespace App\Http\Requests;

use App\Support\DaftarLaporanEmail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pengaturan email laporan harian di menu Akun. Email tujuan boleh dipisah baris baru, koma, titik koma, atau spasi.
 * Bila dinyalakan, minimal satu email dan satu laporan wajib ada.
 */
class EmailLaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $alamat = preg_split('/[\s,;]+/', mb_strtolower(trim((string) $this->input('alamat_teks', ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $this->merge([
            'aktif'   => $this->boolean('aktif'),
            'alamat'  => array_values(array_unique($alamat)),
            'laporan' => array_values(array_filter((array) $this->input('laporan', []), 'is_string')),
        ]);
    }

    public function rules(): array
    {
        return [
            'aktif'     => ['boolean'],
            'alamat'    => [Rule::requiredIf($this->boolean('aktif')), 'array', 'max:'.DaftarLaporanEmail::MAKS_ALAMAT],
            'alamat.*'  => ['email:rfc', 'max:150'],
            'laporan'   => [Rule::requiredIf($this->boolean('aktif')), 'array'],
            'laporan.*' => [Rule::in(array_keys(DaftarLaporanEmail::pilihan((string) $this->user()?->role)))],
        ];
    }

    public function messages(): array
    {
        return [
            'alamat.required' => 'Isi minimal satu email tujuan.',
            'alamat.max'      => 'Email tujuan maksimal :max alamat.',
            'alamat.*.email'  => 'Alamat ":input" bukan email yang benar.',
            'alamat.*.max'    => 'Alamat email terlalu panjang.',
            'laporan.required' => 'Centang minimal satu laporan.',
            'laporan.*.in'    => 'Pilih laporan dari daftar.',
        ];
    }

    /** Galat alamat ditampilkan di bawah kotak isian email (alamat_teks). */
    protected function failedValidation(Validator $validator): void
    {
        $pesan = $validator->errors();

        foreach ($pesan->keys() as $kunci) {
            if (str_starts_with($kunci, 'alamat') && ! $pesan->has('alamat_teks')) {
                $pesan->add('alamat_teks', $pesan->first($kunci));
            }
        }

        parent::failedValidation($validator);
    }

    /** @return array{aktif: bool, alamat: list<string>, laporan: list<string>} */
    public function dataTervalidasi(): array
    {
        return [
            'aktif'   => (bool) $this->validated('aktif'),
            'alamat'  => $this->validated('alamat') ?? [],
            'laporan' => $this->validated('laporan') ?? [],
        ];
    }
}
