<?php

namespace App\Http\Controllers;

use App\Http\Requests\LiburRequest;
use App\Models\Cabang;
use App\Models\PenjualanHarian;
use App\Models\PenjualanLibur;
use App\Services\KontrolSistemService;
use App\Services\LiburService;
use App\Services\PenjualanService;
use App\Support\HariOperasional;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hari libur cabang untuk Admin Cabang (cabangnya sendiri), Admin Pusat, dan Admin Utama (semua cabang).
 * Route ada di tiap kelompok peran (cabang.libur.*, pusat.libur.*, admin-utama.libur.*); halaman daftar hanya untuk
 * Admin Pusat, peran lain melihat hari libur di daftar Penjualan.
 */
class LiburController extends Controller
{
    public function __construct(private LiburService $libur)
    {
    }

    public function index(Request $request): View
    {
        return view('libur.index', [
            'daftar'    => PenjualanLibur::query()->with(['cabang:id,nama', 'pencatat:id,nama'])
                ->orderByDesc('tanggal')->orderBy('cabang_id')->paginate(31),
            'rute'      => $this->rute($request),
            'bolehCatat' => app(KontrolSistemService::class)->boleh($request->user(), 'libur.catat'),
        ]);
    }

    /** Popup tandai libur. Admin Cabang memilih dari hari yang belum dicatat; peran lain memilih cabang dan tanggal. */
    public function create(Request $request): View
    {
        $user = $request->user();
        $lokasi = $user->isAdminCabang() ? $this->cabangSendiri($request) : null;
        $tanggal = (string) $request->query('tanggal');

        return view('libur.form', [
            'rute'           => $this->rute($request),
            'lokasi'         => $lokasi,
            'pilihanTanggal' => $lokasi ? $this->pilihanTanggal($lokasi) : [],
            'pilihanCabang'  => $lokasi ? collect() : Cabang::query()->cabang()->aktif()->orderBy('nama')->pluck('nama', 'id'),
            'cabangId'       => (int) $request->query('cabang') ?: null,
            'tanggal'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : HariOperasional::tanggalTeks(),
            'hariIni'        => now()->toDateString(),
            'batasAwal'      => now()->subDays(LiburService::batasMundur($user))->toDateString(),
        ]);
    }

    public function store(LiburRequest $request): RedirectResponse
    {
        $lokasi = $request->user()->isAdminCabang()
            ? $this->cabangSendiri($request)
            : Cabang::query()->findOrFail((int) $request->validated('cabang_id'));

        try {
            $libur = $this->libur->tandai($request->user(), $lokasi, $request->validated('tanggal'));
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', $lokasi->nama.' libur '.$libur->tanggal->translatedFormat('l, j M Y').'.');
    }

    public function destroy(Request $request, PenjualanLibur $libur): RedirectResponse
    {
        if ($request->user()->isAdminCabang()) {
            abort_unless($libur->cabang_id === $this->cabangSendiri($request)->id, 404);
        }

        try {
            $this->libur->batal($request->user(), $libur);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Libur '.$libur->tanggal->translatedFormat('l, j M Y').' dibatalkan.');
    }

    /** Awalan nama route peran ini: cabang.libur, pusat.libur, atau admin-utama.libur. */
    private function rute(Request $request): string
    {
        return explode('.', (string) $request->route()?->getName())[0].'.libur';
    }

    private function cabangSendiri(Request $request): Cabang
    {
        $lokasi = $request->user()->cabang;

        abort_unless($lokasi && ! $lokasi->isPusat() && $lokasi->aktif, 403, 'Akun Anda belum terhubung ke cabang yang aktif. Hubungi Admin Utama.');

        return $lokasi;
    }

    /**
     * Hari ini (bila belum dicatat dan belum libur) lalu hari yang terlewat, terbaru dulu.
     *
     * @return array<string, string> [Y-m-d => label]
     */
    private function pilihanTanggal(Cabang $lokasi): array
    {
        $hariIni = HariOperasional::tanggal();
        $tanggal = array_reverse(app(PenjualanService::class)->tanggalTerlewat($lokasi));

        $sudah = PenjualanHarian::query()->diLokasi($lokasi->id)->whereDate('tanggal', $hariIni->toDateString())->exists()
            || $this->libur->libur($lokasi, $hariIni->toDateString());

        if (! $sudah) {
            array_unshift($tanggal, $hariIni);
        }

        return collect($tanggal)->mapWithKeys(fn ($t) => [$t->toDateString() => PenjualanService::labelHari($t)])->all();
    }
}
