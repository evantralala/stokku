<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use App\Models\Kiriman;
use App\Models\PenjualanHarian;
use App\Services\AntreanPersetujuanService;
use App\Services\KontrolSistemService;
use App\Services\PantauanStokService;
use App\Services\PeringatanDiniService;
use App\Support\DaftarKontrol;
use Illuminate\View\View;

/** Dashboard Admin Utama: antrean persetujuan (dengan "Setujui yang wajar"), peringatan dini, dan lampu stok per lokasi. */
class DashboardController extends Controller
{
    /** Peringatan yang langsung tampil; sisanya dilipat. */
    private const MAKS_PERINGATAN = 5;

    /** Baris antrean persetujuan yang tampil di dashboard. */
    private const MAKS_ANTREAN = 5;

    public function __invoke(
        PeringatanDiniService $peringatan,
        PantauanStokService $pantauan,
        KontrolSistemService $kontrol,
        AntreanPersetujuanService $antreanPersetujuan,
    ): View
    {
        $hariIni = now()->toDateString();

        $porsiHariIni = PenjualanHarian::query()
            ->whereDate('tanggal', $hariIni)
            ->withSum('detail as total_porsi', 'porsi')
            ->get(['id', 'cabang_id'])
            ->groupBy('cabang_id')
            ->map(fn ($daftar) => $daftar->sum('total_porsi'));

        $ringkasan = Cabang::query()
            ->aktif()
            ->orderByDesc('jenis')
            ->orderBy('nama')
            ->get()
            ->map(fn (Cabang $l) => [
                'lokasi'       => $l,
                'lampu'        => PantauanStokService::hitungPerLampu($pantauan->daftar($l)),
                'porsiHariIni' => $l->isPusat() ? null : ($porsiHariIni[$l->id] ?? null),
            ]);

        $daftarPeringatan = $kontrol->modulNyala(DaftarKontrol::MODUL_PERINGATAN_DINI) ? $peringatan->daftar() : null;
        $antrean = $antreanPersetujuan->daftar();

        return view('admin-utama.dashboard', [
            'peringatan'    => $daftarPeringatan,
            'ringkasan'     => $ringkasan,
            'jumlahLampu'   => collect(PantauanStokService::TAMPILAN_LAMPU)->keys()
                ->mapWithKeys(fn ($l) => [$l => $ringkasan->sum(fn ($r) => $r['lampu'][$l])])
                ->all(),
            'kirimanJalan'  => Kiriman::query()->where('status', Kiriman::STATUS_DIKIRIM)->count(),
            'antrean'       => $antrean,
            'jumlahWajar'   => $antrean->where('cek.wajar', true)->count(),
            'jumlahMendesak' => $antrean->filter(fn (array $a) => AntreanPersetujuanService::mendesak($a))->count(),
            'maksAntrean'   => self::MAKS_ANTREAN,
            'pilihanJenis'  => AntreanPersetujuanService::JENIS,
            'pilihanTingkat' => PeringatanDiniService::TAMPILAN_TINGKAT,
            'maksPeringatan' => self::MAKS_PERINGATAN,
        ]);
    }
}
