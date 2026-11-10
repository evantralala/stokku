<?php

namespace App\Rules;

use App\Services\PeriodeBukuService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Tanggal transaksi tidak boleh sebelum hari tutup buku terakhir (sudah masuk periode yang diarsipkan). */
class SetelahTutupBuku implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && app(PeriodeBukuService::class)->tanggalTertutup($value)) {
            $fail(PeriodeBukuService::PESAN_TANGGAL_TERTUTUP);
        }
    }
}
