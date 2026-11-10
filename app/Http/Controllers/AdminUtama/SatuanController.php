<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\SatuanRequest;
use App\Models\BahanBaku;
use App\Models\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Satuan ukur bahan baku. */
class SatuanController extends DataMasterController
{
    protected string $model = Satuan::class;

    protected string $rute = 'admin-utama.satuan';

    protected string $view = 'admin-utama.satuan';

    protected string $label = 'satuan';

    public function index(Request $request): View
    {
        return $this->tampilkanDaftar($request);
    }

    public function create(): View
    {
        return $this->tampilkanForm();
    }

    public function store(SatuanRequest $request): RedirectResponse
    {
        return $this->simpanBaru($request, $request->validated());
    }

    public function edit(Satuan $satuan): View
    {
        return $this->tampilkanForm($satuan);
    }

    public function update(SatuanRequest $request, Satuan $satuan): RedirectResponse
    {
        return $this->simpanPerubahan($satuan, $request->validated());
    }

    public function ubahAktif(Request $request, Satuan $satuan): RedirectResponse
    {
        return $this->aturAktif($request, $satuan);
    }

    public function destroy(Satuan $satuan): RedirectResponse
    {
        return $this->hapusData($satuan);
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query
            ->withCount(['bahanBakuDasar as jumlah_dasar', 'bahanBakuBeli as jumlah_beli'])
            ->orderByDesc('aktif')
            ->orderBy('nama');
    }

    protected function dataForm(?Model $item): array
    {
        return ['maksNama' => SatuanRequest::MAKS_NAMA];
    }

    protected function cegahArsip(Model $item): ?string
    {
        $jumlah = BahanBaku::query()->tampil()
            ->where(fn (Builder $q) => $q->where('satuan_dasar_id', $item->id)->orWhere('satuan_beli_id', $item->id))->count();

        return $jumlah > 0 ? "Satuan {$item->nama} masih dipakai {$jumlah} bahan baku. Ganti satuan bahannya dulu." : null;
    }

}
