<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Controller;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Services\PantauanStokService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Dashboard Admin Pusat: kiriman yang harus disiapkan, dokumen yang perlu ditindaklanjuti, dan stok gudang. */
class DashboardController extends Controller
{
    private const MAKS_BAHAN = 8;

    public function __invoke(Request $request, PantauanStokService $pantauan): View
    {
        $lokasi = $request->user()->cabang;

        if (! $lokasi || ! $lokasi->isPusat() || ! $lokasi->aktif) {
            return view('pusat.dashboard', ['lokasi' => null]);
        }

        $stok = $pantauan->daftar($lokasi);

        return view('pusat.dashboard', [
            'lokasi'          => $lokasi,
            'jumlahPerLampu'  => PantauanStokService::hitungPerLampu($stok),
            'bahanBermasalah' => $this->bermasalah($stok),
            'masukMenunggu'   => BarangMasuk::query()->diLokasi($lokasi->id)->where('status', BarangMasuk::STATUS_MENUNGGU)->count(),
            'perluDiperbaiki' => BarangMasuk::query()->diLokasi($lokasi->id)->where('status', BarangMasuk::STATUS_REVISI)->count()
                + BarangKeluar::query()->diLokasi($lokasi->id)->rusak()->where('status', BarangKeluar::STATUS_REVISI)->count(),
        ]);
    }

    /** Bahan berlampu merah atau kuning, yang paling gawat di atas. */
    private function bermasalah(Collection $stok): Collection
    {
        $urutan = array_flip(array_keys(PantauanStokService::TAMPILAN_STATUS));

        return $stok
            ->whereIn('lampu', [PantauanStokService::LAMPU_MERAH, PantauanStokService::LAMPU_KUNING])
            ->sortBy(fn ($r) => [$urutan[$r['status']], $r['bahan']->nama])
            ->take(self::MAKS_BAHAN)
            ->values();
    }
}
