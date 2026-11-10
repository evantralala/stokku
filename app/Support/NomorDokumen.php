<?php

namespace App\Support;

use App\Models\Cabang;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen berurutan per jenis, lokasi, dan tanggal.
 * Format: AWALAN-KODELOKASI-yymmdd-urut, mis. "BM-GP-261004-003".
 * Dipanggil di dalam DB::transaction() supaya kunci baris berlaku sampai dokumen tersimpan.
 */
final class NomorDokumen
{
    public static function berikutnya(string $tabel, string $awalan, Cabang $lokasi, DateTimeInterface $tanggal): string
    {
        $prefiks = "{$awalan}-{$lokasi->kode}-{$tanggal->format('ymd')}-";

        $urutTerakhir = DB::table($tabel)
            ->where('nomor', 'like', $prefiks.'%')
            ->lockForUpdate()
            ->pluck('nomor')
            ->map(fn (string $nomor) => (int) substr($nomor, strlen($prefiks)))
            ->max() ?? 0;

        $urut = $urutTerakhir + 1;

        return $prefiks.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }
}
