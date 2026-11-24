<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use App\Models\Opname;
use App\Models\PenjualanHarian;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Services\KontrolSistemService;
use App\Services\PantauanStokService;
use App\Services\PenjualanService;
use App\Services\PeriodeBukuService;
use App\Services\PermintaanService;
use App\Support\HariOperasional;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Dashboard Admin Cabang: tugas hari ini (penjualan, terima barang) dan bahan yang perlu diminta. */
class DashboardController extends Controller
{
    private const MAKS_BAHAN = 6;

    public function __invoke(Request $request, PantauanStokService $pantauan, PermintaanService $permintaan, KontrolSistemService $kontrol, PeriodeBukuService $periode, PenjualanService $penjualan): View
    {
        $lokasi = $request->user()->cabang;

        if (! $lokasi || $lokasi->isPusat() || ! $lokasi->aktif) {
            return view('cabang.dashboard', ['lokasi' => null]);
        }

        $stok = $pantauan->daftar($lokasi);

        return view('cabang.dashboard', [
            'lokasi'             => $lokasi,
            'penjualanHariIni'   => $this->penjualanHariIni($lokasi),
            'hariOperasional'    => HariOperasional::tanggal(),
            'hariDiarsipkan'     => $periode->penjualanTertutup($lokasi->id, HariOperasional::tanggalTeks()),
            'penjualanTerlewat'  => $penjualan->tanggalTerlewat($lokasi),
            'permintaanMenunggu' => Permintaan::query()->diLokasi($lokasi->id)->where('status', Permintaan::STATUS_MENUNGGU)
                ->whereHas('detail', fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU))->count(),
            'jumlahUsulan'       => $permintaan->usulan($lokasi)->count(),
            'bolehMinta'         => $kontrol->boleh($request->user(), 'permintaan.catat'),
            'opnameBerjalan'     => Opname::query()->diLokasi($lokasi->id)->belumSelesai()->first(),
            'jumlahPerLampu'     => PantauanStokService::hitungPerLampu($stok),
            'segeraHabis'        => $stok
                ->filter(fn ($r) => $r['saldo'] <= 0 || $r['cukup_hari'] !== null)
                ->sortBy(fn ($r) => $r['saldo'] <= 0 ? -1 : $r['cukup_hari'])
                ->take(self::MAKS_BAHAN)
                ->values(),
        ]);
    }

    private function penjualanHariIni(Cabang $lokasi): ?PenjualanHarian
    {
        return PenjualanHarian::query()
            ->diLokasi($lokasi->id)
            ->whereDate('tanggal', HariOperasional::tanggalTeks())
            ->withSum('detail as total_porsi', 'porsi')
            ->first();
    }
}
