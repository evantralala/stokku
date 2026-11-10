<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\UnduhPeriode;
use App\Http\Requests\Cabang\PenjualanRequest;
use App\Http\Requests\Cabang\PerkiraanPenjualanRequest;
use App\Models\BarangKeluarDetail;
use App\Models\Menu;
use App\Models\PenjualanHarian;
use App\Services\EksporDaftarService;
use App\Services\PenjualanService;
use App\Services\PerkiraanStokService;
use App\Support\HariOperasional;
use App\Support\Periode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Penjualan harian cabang: porsi terjual per menu, pemakaian bahan dihitung dari resep. */
class PenjualanController extends CabangController
{
    use UnduhPeriode;

    /** Awalan nama route; Admin Utama memakai controller turunan dengan awalan admin-utama.penjualan. */
    protected string $rute = 'cabang.penjualan';

    public function __construct(private PenjualanService $layanan, private PerkiraanStokService $perkiraan)
    {
    }

    /** Perkiraan stok dari isi form yang sedang diketik (potongan HTML). ?penjualan= untuk penjualan yang sedang diubah. */
    public function perkiraan(PerkiraanPenjualanRequest $request): View
    {
        $lokasi = $this->lokasi($request);
        $ganti = $request->filled('penjualan')
            ? PenjualanHarian::query()->diLokasi($lokasi->id)->find((int) $request->query('penjualan'))
            : null;

        return view('stok._perkiraan', [
            'perkiraan' => $this->perkiraan->penjualan($lokasi, [[
                'tanggal' => $ganti?->tanggal->toDateString() ?? $request->validated('tanggal') ?? HariOperasional::tanggalTeks(),
                'porsi'   => $request->validated('porsi') ?? [],
                'ganti'   => $ganti,
            ]]),
        ]);
    }

    /** Excel penjualan harian cabang per periode, satu baris per menu per hari. */
    public function unduh(Request $request, EksporDaftarService $ekspor): View|StreamedResponse
    {
        $lokasi = $this->lokasi($request);

        return $this->unduhPerPeriode($request, 'Penjualan harian '.$lokasi->nama, 'cabang.penjualan.unduh', route('cabang.penjualan'),
            fn (Periode $periode) => $ekspor->penjualan($lokasi, $periode));
    }

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $daftar = PenjualanHarian::query()
            ->diLokasi($lokasi->id)
            ->withSum('detail as total_porsi', 'porsi')
            ->withCount('detail')
            ->with('pencatat:id,nama')
            ->orderByDesc('tanggal')
            ->paginate(31);

        return view('cabang.penjualan.index', [
            'daftar'        => $daftar,
            'lokasi'        => $lokasi,
            'sudahHariIni'  => PenjualanHarian::query()->diLokasi($lokasi->id)->whereDate('tanggal', HariOperasional::tanggalTeks())->first(),
            'hariOperasional' => HariOperasional::tanggal(),
            'hariDiarsipkan'  => app(\App\Services\PeriodeBukuService::class)->penjualanTertutup($lokasi->id, HariOperasional::tanggalTeks()),
        ]);
    }

    /** ?tanggal=Y-m-d; bila tanggal itu sudah dicatat, langsung dibuka untuk diubah. */
    public function create(Request $request): View|RedirectResponse
    {
        $lokasi = $this->lokasi($request);

        $tanggal = $this->tanggalDariQuery($request);

        $ada = PenjualanHarian::query()->diLokasi($lokasi->id)->whereDate('tanggal', $tanggal)->first();
        if ($ada) {
            return redirect()->route($this->rute.'.ubah', $ada);
        }

        return $this->form($request, null, $tanggal, []);
    }

    public function store(PenjualanRequest $request): RedirectResponse
    {
        $tanggal = $request->validated('tanggal');

        try {
            $penjualan = $this->layanan->catat($request->user(), $this->lokasi($request), $tanggal, $request->validated('porsi'));
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()
            ->route($this->rute.'.lihat', $penjualan)
            ->with('sukses', 'Penjualan '.$penjualan->tanggal->translatedFormat('j F Y').' tersimpan. Pemakaian bahan sudah dikurangkan dari stok.');
    }

    public function show(Request $request, PenjualanHarian $penjualan): View
    {
        $this->pastikanMilikLokasi($request, $penjualan);

        $penjualan->load(['pencatat:id,nama', 'cabang:id,nama', 'detail.menu:id,nama,foto,kategori_menu_id', 'detail.menu.kategoriMenu:id,nama,urutan']);

        $pemakaian = BarangKeluarDetail::query()
            ->where('barang_keluar_id', $penjualan->barang_keluar_id)
            ->with('bahanBaku:id,nama,satuan_dasar_id', 'bahanBaku.satuanDasar:id,nama')
            ->get()
            ->sortBy(fn (BarangKeluarDetail $d) => $d->bahanBaku->nama)
            ->values();

        return view('cabang.penjualan.lihat', [
            'rute'      => $this->rute,
            'bisaUbah'  => $this->bolehUbah($penjualan),
            'riwayat'   => $this->riwayatUbah($penjualan),
            'penjualan' => $penjualan,
            'detail'    => $penjualan->detail->sortBy(fn ($d) => [$d->menu->kategoriMenu->urutan, $d->menu->nama])->values(),
            'pemakaian' => $pemakaian,
        ]);
    }

    public function edit(Request $request, PenjualanHarian $penjualan): View|RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $penjualan);

        if (! $this->bolehUbah($penjualan)) {
            return redirect()->route($this->rute.'.lihat', $penjualan)
                ->with('galat', $this->pesanTidakBisaUbah($penjualan));
        }

        return $this->form($request, $penjualan, $penjualan->tanggal->toDateString(), $penjualan->detail()->pluck('porsi', 'menu_id')->all());
    }

    public function update(PenjualanRequest $request, PenjualanHarian $penjualan): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $penjualan);

        try {
            $this->layanan->ubah($penjualan, $request->user(), $request->validated('porsi'), $this->hakPenuh());
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()
            ->route($this->rute.'.lihat', $penjualan)
            ->with('sukses', 'Perubahan penjualan tersimpan. Pemakaian bahan sudah disesuaikan.');
    }

    /** Mengubah tanpa batas waktu dan tanpa mengganti pencatat (halaman Admin Utama). */
    protected function hakPenuh(): bool
    {
        return false;
    }

    /** Admin Cabang: catatan cabangnya sendiri sebelum batas waktu. Admin Utama menimpa ini (tanpa batas waktu). */
    protected function bolehUbah(PenjualanHarian $penjualan): bool
    {
        return $penjualan->bisaDiubah();
    }

    protected function pesanTidakBisaUbah(PenjualanHarian $penjualan): string
    {
        return $penjualan->dicatatCabang()
            ? 'Penjualan ini sudah melewati batas waktu perubahan.'
            : 'Penjualan ini diimpor Admin Utama, jadi tidak bisa diubah dari cabang.';
    }

    /**
     * Riwayat perubahan catatan (terbaru dulu) dari log penjualan.ubah: siapa, kapan, dan porsi lama → baru.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\LogPerubahan>
     */
    protected function riwayatUbah(PenjualanHarian $penjualan)
    {
        return \App\Models\LogPerubahan::query()
            ->with('oleh:id,nama,role')
            ->where('aksi', 'penjualan.ubah')
            ->where('objek_tipe', class_basename($penjualan))
            ->where('objek_id', $penjualan->id)
            ->latest('created_at')->latest('id')
            ->get();
    }

    /** @param  array<int, int>  $porsiLama  [menu_id => porsi] */
    protected function form(Request $request, ?PenjualanHarian $penjualan, string $tanggal, array $porsiLama): View
    {
        $menu = Menu::query()
            ->aktif()
            ->with('kategoriMenu:id,nama,urutan')
            ->orderBy('nama')
            ->get(['id', 'nama', 'foto', 'kategori_menu_id']);

        $tanpaResep = $menu->filter(fn (Menu $m) => $m->tanggalVersiBerlaku($tanggal) === null)->pluck('id')->all();

        $kelompok = $menu
            ->groupBy(fn (Menu $m) => $m->kategoriMenu->id)
            ->sortBy(fn ($daftar) => [$daftar->first()->kategoriMenu->urutan, $daftar->first()->kategoriMenu->nama]);

        return view('cabang.penjualan.form', [
            'rute'       => $this->rute,
            'penjualan'  => $penjualan,
            'tanggal'    => $tanggal,
            'kelompok'   => $kelompok,
            'porsiLama'  => $porsiLama,
            'tanpaResep' => $tanpaResep,
            'perkiraan'  => $this->perkiraan->penjualan($this->lokasi($request), [[
                'tanggal' => $tanggal,
                'porsi'   => (array) old('porsi', $porsiLama),
                'ganti'   => $penjualan,
            ]]),
            'hariIni'    => now()->toDateString(),
            'batasAwal'  => now()->subDays(PenjualanHarian::BATAS_MUNDUR_HARI)->toDateString(),
        ]);
    }

    private function tanggalDariQuery(Request $request): string
    {
        $tanggal = $request->query('tanggal');

        if (is_string($tanggal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $hasil = Carbon::parse($tanggal);
            if ($hasil->isPast() || $hasil->isToday()) {
                return $hasil->toDateString();
            }
        }

        return HariOperasional::tanggalTeks();
    }

    protected function pastikanMilikLokasi(Request $request, PenjualanHarian $penjualan): void
    {
        abort_unless((int) $penjualan->cabang_id === $this->lokasi($request)->id, 404);
    }
}
