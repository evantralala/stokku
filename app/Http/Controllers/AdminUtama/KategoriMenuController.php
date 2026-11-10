<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\KategoriMenuRequest;
use App\Models\KategoriMenu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KategoriMenuController extends DataMasterController
{
    protected string $model = KategoriMenu::class;
    protected string $rute = 'admin-utama.kategori-menu';
    protected string $view = 'admin-utama.kategori-menu';
    protected string $label = 'kategori menu';

    public function index(Request $request): View
    {
        return $this->tampilkanDaftar($request);
    }

    public function create(): View
    {
        return $this->tampilkanForm();
    }

    public function store(KategoriMenuRequest $request): RedirectResponse
    {
        return $this->simpanBaru($request, $request->validated());
    }

    public function edit(KategoriMenu $kategoriMenu): View
    {
        return $this->tampilkanForm($kategoriMenu);
    }

    public function update(KategoriMenuRequest $request, KategoriMenu $kategoriMenu): RedirectResponse
    {
        return $this->simpanPerubahan($kategoriMenu, $request->validated());
    }

    public function ubahAktif(Request $request, KategoriMenu $kategoriMenu): RedirectResponse
    {
        return $this->aturAktif($request, $kategoriMenu);
    }

    public function destroy(KategoriMenu $kategoriMenu): RedirectResponse
    {
        return $this->hapusData($kategoriMenu);
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query
            ->withCount('menu as jumlah_menu')
            ->orderByDesc('aktif')
            ->orderBy('urutan')
            ->orderBy('nama');
    }

    protected function dataForm(?Model $item): array
    {
        return [
            'maksNama'         => KategoriMenuRequest::MAKS_NAMA,
            'urutanBerikutnya' => KategoriMenu::urutanBerikutnya(),
        ];
    }

    protected function cegahArsip(Model $item): ?string
    {
        $jumlah = $item->menu()->tampil()->count();

        return $jumlah > 0 ? "Masih ada {$jumlah} menu di kategori {$item->nama}. Pindahkan atau hapus menunya dulu." : null;
    }

}
