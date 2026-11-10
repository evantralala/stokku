<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\MenuRequest;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Services\FotoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MenuController extends DataMasterController
{
    protected string $model = Menu::class;
    protected string $rute = 'admin-utama.menu';
    protected string $view = 'admin-utama.menu';
    protected string $label = 'menu';

    public function __construct(private FotoService $foto)
    {
    }

    public function index(Request $request): View
    {
        return $this->tampilkanDaftar($request);
    }

    public function create(): View
    {
        return $this->tampilkanForm();
    }

    /** Setelah menu baru disimpan, langsung lanjut mengisi resepnya. */
    public function store(MenuRequest $request): RedirectResponse
    {
        $data = $request->dataMenu()
            + $this->foto->perubahan($request->file('foto'), false, FotoService::FOLDER_MENU);

        $arsip = Menu::cariArsip($data);
        $menu = DB::transaction(function () use ($data, $arsip) {
            if ($arsip) {
                $arsip->forceFill(['dihapus_pada' => null])->fill($data + $this->nilaiBawaanBaru())->save();

                return $arsip;
            }

            return Menu::create($data + $this->nilaiBawaanBaru());
        });
        $awal = $arsip ? "Menu {$menu->nama} ditambahkan kembali. Riwayat lamanya tersambung lagi." : "Menu {$menu->nama} berhasil ditambahkan.";

        if ($request->boolean('tambah_lagi')) {
            return self::tambahLagi($request, 'admin-utama.menu', $awal);
        }

        return redirect()
            ->route('admin-utama.menu.resep.ubah', $menu)
            ->with('sukses', $awal.($arsip ? ' Periksa resepnya.' : ' Sekarang isi resepnya.'));
    }

    public function edit(Menu $menu): View
    {
        return $this->tampilkanForm($menu);
    }

    public function update(MenuRequest $request, Menu $menu): RedirectResponse
    {
        $data = $request->dataMenu()
            + $this->foto->perubahan($request->file('foto'), $request->boolean('hapus_foto'), FotoService::FOLDER_MENU);

        DB::transaction(fn () => $menu->update($data));

        return redirect()
            ->route($this->rute)
            ->with('sukses', "Perubahan menu {$menu->nama} berhasil disimpan.");
    }

    public function ubahAktif(Request $request, Menu $menu): RedirectResponse
    {
        return $this->aturAktif($request, $menu);
    }

    public function destroy(Menu $menu): RedirectResponse
    {
        return $this->hapusData($menu);
    }

    protected function bacaFilter(Request $request): array
    {
        return [
            'kategori' => (int) $request->query('kategori') ?: null,
            'resep'    => $request->query('resep') === 'belum' ? 'belum' : null,
        ];
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $hariIni = now()->toDateString();

        $query
            ->when($filter['kategori'], fn (Builder $q, int $id) => $q->where('kategori_menu_id', $id))
            ->when($filter['resep'], fn (Builder $q) => $q->tanpaResepBerlaku())
            ->with('kategoriMenu:id,nama')
            ->withMax(['resep as versi_berlaku' => fn ($q) => $q->whereDate('berlaku_sejak', '<=', $hariIni)], 'berlaku_sejak')
            ->withExists(['resep as ada_jadwal' => fn ($q) => $q->whereDate('berlaku_sejak', '>', $hariIni)])
            ->withCasts(['versi_berlaku' => 'date'])
            ->orderByDesc('aktif')
            ->orderBy('nama');
    }

    protected function dataDaftar(array $filter): array
    {
        return [
            'pilihanKategori' => KategoriMenu::query()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id'),
            'tanpaResep'      => Menu::query()->aktif()->tanpaResepBerlaku()->count(),
        ];
    }

    protected function dataForm(?Model $item): array
    {
        $dipakai = $item?->kategori_menu_id;

        return [
            'maks'            => MenuRequest::MAKS,
            'pilihanKategori' => KategoriMenu::query()
                ->where(fn (Builder $q) => $q->where('aktif', true)->when($dipakai, fn ($q) => $q->orWhere('id', $dipakai)))
                ->orderBy('urutan')
                ->orderBy('nama')
                ->pluck('nama', 'id')
                ->all(),
        ];
    }
}
