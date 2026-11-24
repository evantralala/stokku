<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Models\User;

/** File Excel/CSV penjualan harian yang diunggah Admin Cabang untuk cabangnya sendiri. */
class ImporPenjualanRequest extends ImporDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG;
    }

    /** tanggal: pilihan tanggal di popup unggah (hanya tanggal yang ditawarkan yang dipakai, lihat pakaiTanggal). */
    public function rules(): array
    {
        return parent::rules() + [
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'tanggal.date_format' => 'Pilih tanggal dari pilihan.',
        ];
    }
}
