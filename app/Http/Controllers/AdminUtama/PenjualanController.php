<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Cabang\PenjualanController as PenjualanCabangController;
use App\Models\Cabang;
use App\Models\PenjualanHarian;
use App\Services\PeriodeBukuService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catatan penjualan semua cabang untuk Admin Utama: lihat, dan ubah kapan pun untuk membetulkan salah input
 * (cabang hanya bisa sampai akhir hari berikutnya). Halaman lihat dan form sama dengan milik cabang.
 */
class PenjualanController extends PenjualanCabangController
{
    protected string $rute = 'admin-utama.penjualan';

    public function index(Request $request): View
    {
        $pilihanLokasi = Cabang::query()->cabang()->orderBy('nama')->pluck('nama', 'id');
        $lokasi = (int) $request->query('lokasi');
        $lokasi = $pilihanLokasi->has($lokasi) ? $lokasi : null;

        return view('admin-utama.penjualan.index', [
            'daftar' => PenjualanHarian::query()
                ->when($lokasi, fn ($q) => $q->diLokasi($lokasi))
                ->with(['pencatat:id,nama', 'cabang:id,nama'])
                ->withSum('detail as total_porsi', 'porsi')
                ->withCount('detail')
                ->orderByDesc('tanggal')->orderBy('cabang_id')
                ->paginate(31)
                ->withQueryString(),
            'pilihanLokasi' => $pilihanLokasi,
            'lokasi'        => $lokasi,
        ]);
    }

    /** Cabang dari catatan yang dibuka (route atau ?penjualan= untuk perkiraan stok). */
    protected function lokasi(Request $request): Cabang
    {
        $penjualan = $request->route('penjualan');

        if (! $penjualan instanceof PenjualanHarian && $request->filled('penjualan')) {
            $penjualan = PenjualanHarian::query()->find((int) $request->query('penjualan'));
        }

        abort_unless($penjualan instanceof PenjualanHarian, 404);

        return $penjualan->cabang;
    }

    protected function pastikanMilikLokasi(Request $request, PenjualanHarian $penjualan): void
    {
    }

    protected function hakPenuh(): bool
    {
        return true;
    }

    /** Kapan pun, kecuali tanggalnya sudah masuk periode buku yang ditutup. */
    protected function bolehUbah(PenjualanHarian $penjualan): bool
    {
        return ! app(PeriodeBukuService::class)->penjualanTertutup($penjualan->cabang_id, $penjualan->tanggal->toDateString());
    }

    protected function pesanTidakBisaUbah(PenjualanHarian $penjualan): string
    {
        return PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP;
    }
}
