<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rentang tanggal laporan dari query string (?dari=Y-m-d&sampai=Y-m-d).
 * Isian yang salah tidak menggagalkan halaman: diganti nilai bawaan (awal bulan ini sampai hari ini),
 * urutan yang terbalik ditukar, dan rentang dibatasi paling panjang MAKS_HARI.
 */
final class Periode
{
    public const MAKS_HARI = 366;

    private function __construct(
        public readonly Carbon $dari,
        public readonly Carbon $sampai,
    ) {
    }

    public static function dariRequest(Request $request): self
    {
        $dari = self::baca($request->query('dari')) ?? now()->startOfMonth();
        $sampai = self::baca($request->query('sampai')) ?? now();

        if ($dari->greaterThan($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        if ($dari->diffInDays($sampai) > self::MAKS_HARI) {
            $dari = $sampai->copy()->subDays(self::MAKS_HARI);
        }

        return new self($dari->copy()->startOfDay(), $sampai->copy()->endOfDay());
    }

    public static function antara(Carbon $dari, Carbon $sampai): self
    {
        return new self($dari->copy()->startOfDay(), $sampai->copy()->endOfDay());
    }

    /** Pilihan cepat untuk tombol saringan: [label => [dari, sampai]]. */
    public static function pilihanCepat(): array
    {
        return [
            '7 Hari'     => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'Bulan Ini'  => [now()->startOfMonth()->toDateString(), now()->toDateString()],
            'Bulan Lalu' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
        ];
    }

    public function dariTanggal(): string
    {
        return $this->dari->toDateString();
    }

    public function sampaiTanggal(): string
    {
        return $this->sampai->toDateString();
    }

    /** "1–31 Okt 2026" atau "28 Sep – 4 Okt 2026". */
    public function label(): string
    {
        return $this->dari->isSameDay($this->sampai)
            ? $this->dari->translatedFormat('j M Y')
            : $this->dari->translatedFormat('j M Y').' – '.$this->sampai->translatedFormat('j M Y');
    }

    private static function baca(mixed $nilai): ?Carbon
    {
        if (! is_string($nilai) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return null;
        }

        [$t, $b, $h] = array_map('intval', explode('-', $nilai));

        return checkdate($b, $h, $t) ? Carbon::create($t, $b, $h) : null;
    }
}
