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
use App\Services\PeriodeBukuService;
use App\Services\PermintaanService;
use App\Support\HariOperasional;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Dashboard Admin Cabang: tugas hari ini (penjualan, terima barang) dan bahan yang perlu diminta. */
class DashboardController extends Controller
{
    private const MAKS_BAHAN = 6;

    public function __invoke(Request $request, PantauanStokService $pantauan, PermintaanService $permintaan, KontrolSistemService $kontrol, PeriodeBukuService $periode): View
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
            'penjualanTerlewat'  => $this->penjualanTerlewat($lokasi, $periode),
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

    /**
     * Hari sebelum hari operasional yang belum dicatat penjualannya, paling lama BATAS_MUNDUR_HARI ke belakang
     * (batas cabang boleh mencatat sendiri), tidak sebelum cabang dibuat, dan tidak di periode yang sudah tutup buku.
     *
     * @return list<\Illuminate\Support\Carbon>
     */
    private function penjualanTerlewat(Cabang $lokasi, PeriodeBukuService $periode): array
    {
        $akhir = HariOperasional::tanggal()->subDay();
        $awal = now()->startOfDay()->subDays(PenjualanHarian::BATAS_MUNDUR_HARI);

        if ($lokasi->created_at && $lokasi->created_at->copy()->startOfDay()->gt($awal)) {
            $awal = $lokasi->created_at->copy()->startOfDay();
        }

        if ($awal->gt($akhir)) {
            return [];
        }

        $sudah = PenjualanHarian::query()->diLokasi($lokasi->id)
            ->whereDate('tanggal', '>=', $awal->toDateString())->whereDate('tanggal', '<=', $akhir->toDateString())
            ->pluck('tanggal')->map(fn ($t) => \Illuminate\Support\Carbon::parse($t)->toDateString())->flip();

        $hasil = [];
        for ($hari = $akhir->copy(); $hari->gte($awal); $hari->subDay()) {
            if (! isset($sudah[$hari->toDateString()]) && ! $periode->penjualanTertutup($lokasi->id, $hari->toDateString())) {
                $hasil[] = $hari->copy();
            }
        }

        return array_reverse($hasil);
    }
}
