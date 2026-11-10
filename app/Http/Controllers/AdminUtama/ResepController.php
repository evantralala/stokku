<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\ResepRequest;
use App\Models\BahanBaku;
use App\Models\Menu;
use App\Models\Resep;
use App\Services\ResepService;
use App\Support\Angka;
use Illuminate\Support\Carbon;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Resep per menu, dengan riwayat versi berdasarkan tanggal berlaku. */
class ResepController extends Controller
{
    public function __construct(private ResepService $resep)
    {
    }

    public function index(Menu $menu): View
    {
        $hariIni = now()->toDateString();
        $versiBerlaku = $menu->tanggalVersiBerlaku();

        $versi = $menu->versiResep()->map(fn (string $tanggal) => [
            'tanggal' => $tanggal,
            'status'  => match (true) {
                $tanggal > $hariIni       => 'terjadwal',
                $tanggal === $versiBerlaku => 'berlaku',
                default                   => 'lama',
            },
            'baris'   => $menu->resepVersi($tanggal),
        ]);

        return view('admin-utama.menu.resep', [
            'menu'  => $menu->load('kategoriMenu:id,nama'),
            'versi' => $versi,
        ]);
    }

    /**
     * Form versi resep. ?tanggal=Y-m-d membuka versi tanggal itu (hari ini atau setelahnya);
     * tanpa tanggal, isian diawali dari resep yang berlaku supaya cukup mengubah bagian yang perlu.
     * ?salin=ID mengisi form dengan resep yang berlaku dari menu lain.
     */
    public function create(Request $request, Menu $menu): View
    {
        $hariIni = now()->toDateString();
        $tanggal = $this->tanggalValid($request->query('tanggal')) ?? $hariIni;

        if ($tanggal < $hariIni) {
            $tanggal = $hariIni;
        }

        $disalin = $this->menuSumberSalinan($request->query('salin'), $menu);

        if ($disalin) {
            $sumberBaris = $disalin->resepBerlaku();
        } else {
            $sumber = $menu->versiResep()->contains($tanggal)
                ? $tanggal
                : ($menu->tanggalVersiBerlaku() ?? $menu->versiResep()->last());

            $sumberBaris = $sumber ? $menu->resepVersi($sumber) : collect();
        }

        $baris = $sumberBaris->map(fn (Resep $r) => [
            'bahan_baku_id' => $r->bahan_baku_id,
            'jumlah'        => Angka::isian($r->jumlah),
        ])->values()->all();

        $daftarBahan = BahanBaku::query()
            ->aktif()
            ->with('satuanDasar:id,nama')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_dasar_id']);

        // Nama dan satuan juga untuk bahan nonaktif yang masih tercantum di resep lama.
        $idTercantum = collect(old('baris', $baris))->pluck('bahan_baku_id')->filter()->map(fn ($id) => (int) $id);
        $infoBahan = BahanBaku::query()
            ->whereKey($idTercantum->merge($daftarBahan->pluck('id'))->unique()->all())
            ->with('satuanDasar:id,nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_dasar_id'])
            ->mapWithKeys(fn (BahanBaku $b) => [$b->id => ['nama' => $b->nama, 'satuan' => $b->satuanDasar->nama]]);

        return view('admin-utama.menu.resep-form', [
            'menu'         => $menu,
            'tanggal'      => $tanggal,
            'hariIni'      => $hariIni,
            'versiAda'     => $menu->versiResep()->contains($tanggal),
            'baris'        => $baris ?: [['bahan_baku_id' => null, 'jumlah' => '']],
            'infoBahan'    => $infoBahan,
            'dataBahan'    => $daftarBahan->map(fn (BahanBaku $b) => [
                'id'     => $b->id,
                'nama'   => $b->nama,
                'lain'   => (string) $b->nama_lain,
                'satuan' => $b->satuanDasar->nama,
            ])->values(),
            'disalin'      => $disalin,
            'pilihanSalin' => Menu::query()
                ->aktif()
                ->whereKeyNot($menu->id)
                ->whereHas('resep', fn ($q) => $q->whereDate('berlaku_sejak', '<=', $hariIni))
                ->orderBy('nama')
                ->pluck('nama', 'id')
                ->all(),
        ]);
    }

    public function store(ResepRequest $request, Menu $menu): RedirectResponse
    {
        $tanggal = $request->validated('berlaku_sejak');

        try {
            $this->resep->simpanVersi($menu, $tanggal, array_values($request->validated('baris')), $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        $kapan = $tanggal === now()->toDateString()
            ? 'mulai hari ini'
            : 'mulai '.Carbon::parse($tanggal)->translatedFormat('j F Y');

        return redirect()
            ->route('admin-utama.menu.resep', $menu)
            ->with('sukses', "Resep {$menu->nama} berhasil disimpan dan berlaku {$kapan}.");
    }

    public function destroy(Request $request, Menu $menu, string $tanggal): RedirectResponse
    {
        try {
            $this->resep->hapusJadwal($menu, $tanggal, $request->user());
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.menu.resep', $menu)->with('galat', $e->getMessage());
        }

        return redirect()
            ->route('admin-utama.menu.resep', $menu)
            ->with('sukses', 'Resep terjadwal '.Carbon::parse($tanggal)->translatedFormat('j F Y').' berhasil dihapus.');
    }

    /** Menu lain yang punya resep berlaku hari ini, untuk disalin ke form. */
    private function menuSumberSalinan(mixed $id, Menu $menu): ?Menu
    {
        if (! is_numeric($id) || (int) $id === $menu->id) {
            return null;
        }

        $sumber = Menu::find((int) $id);

        return $sumber && $sumber->resepBerlaku()->isNotEmpty() ? $sumber : null;
    }

    private function tanggalValid(mixed $nilai): ?string
    {
        if (! is_string($nilai) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return null;
        }

        return checkdate((int) substr($nilai, 5, 2), (int) substr($nilai, 8, 2), (int) substr($nilai, 0, 4)) ? $nilai : null;
    }
}
