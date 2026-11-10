<?php

namespace App\Http\Requests\Cabang;

use App\Http\Requests\Concerns\AksesMenuUtama;
use App\Http\Requests\Concerns\IsianAman;
use App\Models\PenjualanHarian;
use App\Models\User;
use App\Services\PeriodeBukuService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Jumlah porsi terjual per menu dalam satu hari. */
class PenjualanRequest extends FormRequest
{
    use AksesMenuUtama, IsianAman;

    public const MAKS_PORSI = 9999;

    /** Admin Cabang mencatat dan mengubah; Admin Utama (atau Admin Pusat yang diberi Catatan Penjualan) hanya mengubah catatan yang sudah ada. */
    public function authorize(): bool
    {
        return $this->user()?->role === User::ROLE_ADMIN_CABANG
            || ($this->isMethod('PUT') && $this->bolehMenuUtama());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'porsi' => collect((array) $this->input('porsi', []))
                ->map(fn ($p) => self::digitSel($p))
                ->all(),
        ]);
    }

    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            return [
                'porsi'   => ['required', 'array'],
                'porsi.*' => ['nullable', 'integer', 'min:0', 'max:'.self::MAKS_PORSI],
            ];
        }

        return [
            'tanggal' => [
                'required', 'date_format:Y-m-d', 'before_or_equal:today',
                'after_or_equal:'.now()->subDays(PenjualanHarian::BATAS_MUNDUR_HARI)->toDateString(),
                function (string $atribut, mixed $nilai, Closure $gagal) {
                    if (is_string($nilai) && app(PeriodeBukuService::class)->penjualanTertutup($this->user()?->cabang_id, $nilai)) {
                        $gagal(PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP);
                    }
                },
            ],
            'porsi'   => ['required', 'array'],
            'porsi.*' => ['nullable', 'integer', 'min:0', 'max:'.self::MAKS_PORSI],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.before_or_equal' => 'Tanggal penjualan tidak boleh setelah hari ini.',
            'tanggal.after_or_equal'  => 'Penjualan hanya bisa dicatat paling lama '.PenjualanHarian::BATAS_MUNDUR_HARI.' hari ke belakang. Hubungi Admin Utama untuk tanggal yang lebih lama.',
            'tanggal.date_format'     => 'Pilih tanggal dari kalender.',
            'porsi.required'          => 'Belum ada menu aktif. Minta Admin Utama menambahkan menu terlebih dahulu.',
            'porsi.*.integer'         => 'Isi jumlah porsi dengan bilangan bulat.',
            'porsi.*.max'             => 'Jumlah porsi terlalu besar. Periksa lagi angkanya.',
        ];
    }
}
