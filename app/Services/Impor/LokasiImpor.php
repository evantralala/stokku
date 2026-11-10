<?php

namespace App\Services\Impor;

use App\Models\Cabang;
use App\Support\LembarExcel;
use Illuminate\Support\Collection;

/**
 * Isian kolom Lokasi yang berlaku untuk banyak lokasi sekaligus: "Semua" (semua lokasi aktif, termasuk Gudang Pusat)
 * atau "Semua Cabang" (semua cabang aktif). Dipakai impor stok minimum, stok, dan impor sekaligus.
 */
final class LokasiImpor
{
    public const SEMUA = 'Semua';

    public const SEMUA_CABANG = 'Semua Cabang';

    /** SEMUA, SEMUA_CABANG, atau null bila tulisan itu satu lokasi. */
    public static function jenisSemua(mixed $teks): ?string
    {
        return match (LembarExcel::rapatkanJudul($teks)) {
            'semua', 'semualokasi', 'all' => self::SEMUA,
            'semuacabang', 'allcabang', 'allbranch', 'allbranches' => self::SEMUA_CABANG,
            default => null,
        };
    }

    /** @return Collection<int, Cabang> lokasi aktif yang dituju */
    public static function sasaran(string $jenis): Collection
    {
        return Cabang::query()->tampil()->aktif()
            ->when($jenis === self::SEMUA_CABANG, fn ($q) => $q->cabang())
            ->orderByRaw('jenis = ? desc', [Cabang::JENIS_PUSAT])
            ->orderBy('nama')
            ->get();
    }

    /** Lokasi dari kode atau nama (huruf besar-kecil diabaikan), atau null. */
    public static function cari(Collection $lokasi, string $teks): ?Cabang
    {
        $teks = mb_strtolower($teks);

        return $lokasi->first(fn (Cabang $l) => mb_strtolower($l->kode) === $teks)
            ?? $lokasi->first(fn (Cabang $l) => mb_strtolower($l->nama) === $teks);
    }
}
