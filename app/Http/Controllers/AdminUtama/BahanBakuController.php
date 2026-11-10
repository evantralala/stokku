<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\BahanBakuRequest;
use App\Models\BahanBaku;
use App\Models\Kategori;
use App\Models\Menu;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Services\FotoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BahanBakuController extends DataMasterController
{
    protected string $model = BahanBaku::class;
    protected string $rute = 'admin-utama.bahan-baku';
    protected string $view = 'admin-utama.bahan-baku';
    protected string $label = 'bahan baku';

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

    public function store(BahanBakuRequest $request): RedirectResponse
    {
        return $this->simpanBaru($request, $request->dataTanpaFoto()
            + $this->foto->perubahan($request->file('foto'), false, FotoService::FOLDER_BAHAN));
    }

    public function edit(BahanBaku $bahanBaku): View
    {
        return $this->tampilkanForm($bahanBaku);
    }

    public function update(BahanBakuRequest $request, BahanBaku $bahanBaku): RedirectResponse
    {
        return $this->simpanPerubahan($bahanBaku, $request->dataTanpaFoto()
            + $this->foto->perubahan($request->file('foto'), $request->boolean('hapus_foto'), FotoService::FOLDER_BAHAN));
    }

    public function ubahAktif(Request $request, BahanBaku $bahanBaku): RedirectResponse
    {
        return $this->aturAktif($request, $bahanBaku);
    }

    public function destroy(BahanBaku $bahanBaku): RedirectResponse
    {
        return $this->hapusData($bahanBaku);
    }

    protected function bacaFilter(Request $request): array
    {
        return ['kategori' => (int) $request->query('kategori') ?: null];
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query
            ->when($filter['kategori'], fn (Builder $q, int $id) => $q->where('kategori_id', $id))
            ->with(['kategori:id,nama', 'satuanDasar:id,nama', 'satuanBeli:id,nama'])
            ->orderByDesc('aktif')
            ->orderBy('nama');
    }

    protected function dataDaftar(array $filter): array
    {
        return [
            'pilihanKategori' => Kategori::query()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id'),
        ];
    }

    protected function dataForm(?Model $item): array
    {
        $satuan = $this->pilihanAktifAtauMiliknya(Satuan::class, [$item?->satuan_dasar_id, $item?->satuan_beli_id]);

        return [
            'maks'             => BahanBakuRequest::MAKS,
            'pilihanKategori'  => $this->pilihanAktifAtauMiliknya(Kategori::class, [$item?->kategori_id], 'urutan'),
            'pilihanSatuan'    => $satuan,
            'satuanTerkunci'   => $item !== null && $item->sudahDipakai(),
            'toleransiBawaan'  => Pengaturan::ambil('toleransi_default', 0),
        ];
    }

    /**
     * Pilihan dropdown: semua data aktif ditambah data yang sedang dipakai bahan ini
     * (walau sudah nonaktif), supaya nilai lama tetap tampil.
     *
     * @param  class-string<Model>  $model
     * @param  list<int|null>  $dipakai
     */
    private function pilihanAktifAtauMiliknya(string $model, array $dipakai, string $urutan = 'nama'): array
    {
        $dipakai = array_filter($dipakai);

        return $model::query()
            ->where(fn (Builder $q) => $q->where('aktif', true)->when($dipakai, fn ($q) => $q->orWhereIn('id', $dipakai)))
            ->orderBy($urutan)
            ->orderBy('nama')
            ->pluck('nama', 'id')
            ->all();
    }

    /** Bahan yang masih ada stoknya atau masih dipakai resep menu aktif harus dibereskan dulu sebelum dihapus. */
    protected function cegahArsip(Model $item): ?string
    {
        $stok = DB::table('stok_saldo')->join('cabang', 'cabang.id', '=', 'stok_saldo.cabang_id')
            ->where('bahan_baku_id', $item->id)->where('saldo', '!=', 0)->pluck('cabang.nama');

        if ($stok->isNotEmpty()) {
            return "Stok {$item->nama} masih ada di {$stok->implode(', ')}. Kosongkan stoknya dulu (Stok → ikon sampah), lalu hapus lagi.";
        }

        if (StokMutasi::query()->menunggu()->where('bahan_baku_id', $item->id)->exists()) {
            return "{$item->nama} masih ada di dokumen yang menunggu persetujuan. Selesaikan dokumennya dulu.";
        }

        $menu = Menu::query()->aktif()->get()->filter(fn (Menu $m) => $m->resepBerlaku()->contains('bahan_baku_id', $item->id))->pluck('nama');

        if ($menu->isNotEmpty()) {
            return "{$item->nama} masih dipakai di resep {$menu->implode(', ')}. Ubah resepnya dulu.";
        }

        return null;
    }

}
