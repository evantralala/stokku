<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Http\Requests\Concerns\IsianAman;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** Isi form penjualan yang sedang diketik, untuk menghitung perkiraan stok sebelum disimpan. */
class PerkiraanPenjualanRequest extends FormRequest
{
    use IsianAman, AksesMenuUtama;

    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG || $this->bolehMenuUtama();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'porsi' => collect((array) $this->input('porsi', []))
                ->map(fn ($p) => ($d = self::digitSel($p)) === null ? null : (int) $d)
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'porsi'   => ['array'],
            'porsi.*' => ['nullable', 'integer', 'min:0', 'max:'.PenjualanRequest::MAKS_PORSI],
        ];
    }
}
