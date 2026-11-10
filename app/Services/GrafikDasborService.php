<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\StokMutasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data grafik dashboard (hanya membaca) per periode: Bulan Ini dan Bulan Lalu per hari, Tahun Ini dan Tahun Lalu per bulan.
 *
 * - Pergerakan: jumlah transaksi stok masuk dan keluar. Satu transaksi = satu dokumen (barang masuk, kiriman, penjualan,
 *   barang rusak, opname, dst.) yang mutasinya sah; dokumen yang menambah dan mengurangi stok (mis. opname) dihitung di
 *   kedua sisi. Stok awal tidak dihitung.
 * - Penjualan: total porsi terjual.
 * Transaksi yang sudah dikosongkan saat tutup buku tidak ikut (grafik periode itu kosong).
 */
class GrafikDasborService
{
    public const PERIODE = [
        'bulan-ini'  => 'Bulan Ini',
        'bulan-lalu' => 'Bulan Lalu',
        'tahun-ini'  => 'Tahun Ini',
        'tahun-lalu' => 'Tahun Lalu',
    ];

    public const PERIODE_BAWAAN = 'bulan-ini';

    /** Kunci periode yang sah dari isian alamat; selain itu bawaan. */
    public static function periode(mixed $kunci): string
    {
        return is_string($kunci) && array_key_exists($kunci, self::PERIODE) ? $kunci : self::PERIODE_BAWAAN;
    }

    /** Keterangan periode, mis. "November 2026" atau "Tahun 2025". */
    public static function keterangan(string $periode): string
    {
        [$awal, , $perHari] = self::rentang($periode);

        return $perHari ? $awal->translatedFormat('F Y') : 'Tahun '.$awal->year;
    }

    /**
     * @return list<array{kunci: string, label: string, judul: string, sumbu: bool, masuk: int, keluar: int}>
     */
    public function pergerakan(?Cabang $lokasi, string $periode): array
    {
        [$awal, $akhir, $perHari] = self::rentang($periode);
        $hasil = $this->kerangka($awal, $akhir, $perHari, ['masuk' => 0, 'keluar' => 0]);

        StokMutasi::query()
            ->sah()
            ->where('jenis', '!=', StokMutasi::JENIS_STOK_AWAL)
            ->whereBetween('dibuat_pada', [$awal, $akhir])
            ->when($lokasi, fn ($q) => $q->where('cabang_id', $lokasi->id))
            ->selectRaw('dokumen_tipe, dokumen_id, min(dibuat_pada) as waktu, case when jumlah > 0 then 1 else 0 end as masuk')
            ->groupBy('dokumen_tipe', 'dokumen_id')
            ->groupByRaw('case when jumlah > 0 then 1 else 0 end')
            ->toBase()
            ->get()
            ->each(function ($baris) use (&$hasil, $perHari) {
                $kunci = Carbon::parse($baris->waktu)->format($perHari ? 'Y-m-d' : 'Y-m');
                if (isset($hasil[$kunci])) {
                    $hasil[$kunci][(int) $baris->masuk === 1 ? 'masuk' : 'keluar']++;
                }
            });

        return array_values($hasil);
    }

    /**
     * @param  Cabang|null  $lokasi  null = semua cabang
     * @return list<array{kunci: string, label: string, judul: string, sumbu: bool, porsi: int}>
     */
    public function penjualan(?Cabang $lokasi, string $periode): array
    {
        [$awal, $akhir, $perHari] = self::rentang($periode);
        $hasil = $this->kerangka($awal, $akhir, $perHari, ['porsi' => 0]);

        DB::table('penjualan_detail')
            ->join('penjualan_harian', 'penjualan_harian.id', '=', 'penjualan_detail.penjualan_harian_id')
            ->whereNull('penjualan_harian.deleted_at')
            ->where('penjualan_harian.tanggal', '>=', $awal->toDateString())
            ->where('penjualan_harian.tanggal', '<', $akhir->copy()->addDay()->toDateString())
            ->when($lokasi, fn ($q) => $q->where('penjualan_harian.cabang_id', $lokasi->id))
            ->groupBy('penjualan_harian.tanggal')
            ->selectRaw('penjualan_harian.tanggal as tanggal, sum(penjualan_detail.porsi) as porsi')
            ->get()
            ->each(function ($baris) use (&$hasil, $perHari) {
                $kunci = Carbon::parse($baris->tanggal)->format($perHari ? 'Y-m-d' : 'Y-m');
                if (isset($hasil[$kunci])) {
                    $hasil[$kunci]['porsi'] += (int) $baris->porsi;
                }
            });

        return array_values($hasil);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: bool} [awal, akhir, per hari?] */
    private static function rentang(string $periode): array
    {
        return match (self::periode($periode)) {
            'bulan-lalu' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), true],
            'tahun-ini'  => [now()->startOfYear(), now()->endOfYear(), false],
            'tahun-lalu' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear(), false],
            default      => [now()->startOfMonth(), now()->endOfMonth(), true],
        };
    }

    /** Satu isian kosong per hari (label tanggal; sumbu: 1, 5, 10, …) atau per bulan (label nama bulan). */
    private function kerangka(Carbon $awal, Carbon $akhir, bool $perHari, array $nol): array
    {
        $hasil = [];

        for ($t = $awal->copy(); $t->lte($akhir); $perHari ? $t->addDay() : $t->addMonthNoOverflow()) {
            $hasil[$t->format($perHari ? 'Y-m-d' : 'Y-m')] = [
                'kunci' => $t->format($perHari ? 'Y-m-d' : 'Y-m'),
                'label' => $perHari ? (string) $t->day : $t->translatedFormat('M'),
                'judul' => $perHari ? $t->translatedFormat('l, j M Y') : $t->translatedFormat('F Y'),
                'sumbu' => ! $perHari || $t->day === 1 || $t->day % 5 === 0,
            ] + $nol;
        }

        return $hasil;
    }
}
