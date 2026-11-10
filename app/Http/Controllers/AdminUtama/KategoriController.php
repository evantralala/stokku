<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\KategoriRequest;
use App\Models\Kategori;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Kategori bahan baku. */
class KategoriController extends DataMasterController
{
    protected string $model = Kategori::class;

    protected string $rute = 'admin-utama.kategori';

    protected string $view = 'admin-utama.kategori';

    protected string $label = 'kategori bahan';

    public function index(Request $request): View
    {
        return $this->tampilkanDaftar($request);
    }

    public function create(): View
    {
        return $this->tampilkanForm();
    }

    public function store(KategoriRequest $request): RedirectResponse
    {
        return $this->simpanBaru($request, $request->validated());
    }

    public function edit(Kategori $kategori): View
    {
        return $this->tampilkanForm($kategori);
    }

    public function update(KategoriRequest $request, Kategori $kategori): RedirectResponse
    {
        return $this->simpanPerubahan($kategori, $request->validated());
    }

    public function ubahAktif(Request $request, Kategori $kategori): RedirectResponse
    {
        return $this->aturAktif($request, $kategori);
    }

    public function destroy(Kategori $kategori): RedirectResponse
    {
        return $this->hapusData($kategori);
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query
            ->withCount('bahanBaku as jumlah_bahan')
            ->orderByDesc('aktif')
            ->orderBy('urutan')
            ->orderBy('nama');
    }

    protected function dataForm(?Model $item): array
    {
        return [
            'maksNama' => KategoriRequest::MAKS_NAMA,
            'urutanBerikutnya' => Kategori::urutanBerikutnya(),
        ];
    }

    protected function cegahArsip(Model $item): ?string
    {
        $jumlah = $item->bahanBaku()->tampil()->count();

        return $jumlah > 0 ? "Masih ada {$jumlah} bahan baku di kategori {$item->nama}. Pindahkan atau hapus bahannya dulu." : null;
    }

}
