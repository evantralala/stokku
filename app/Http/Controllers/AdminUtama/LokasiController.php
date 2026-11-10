<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\DataMasterController;
use App\Http\Requests\AdminUtama\LokasiRequest;
use App\Models\Cabang;
use App\Models\StokMutasi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Lokasi stok: Gudang Pusat dan cabang (tabel cabang). */
class LokasiController extends DataMasterController
{
    protected string $model = Cabang::class;

    protected string $rute = 'admin-utama.lokasi';

    protected string $view = 'admin-utama.lokasi';

    protected string $label = 'lokasi';

    public function index(Request $request): View
    {
        return $this->tampilkanDaftar($request);
    }

    public function create(): View
    {
        return $this->tampilkanForm();
    }

    public function store(LokasiRequest $request): RedirectResponse
    {
        return $this->simpanBaru($request, $request->validated());
    }

    public function edit(Cabang $lokasi): View
    {
        return $this->tampilkanForm($lokasi);
    }

    public function update(LokasiRequest $request, Cabang $lokasi): RedirectResponse
    {
        return $this->simpanPerubahan($lokasi, $request->validated());
    }

    public function ubahAktif(Request $request, Cabang $lokasi): RedirectResponse
    {
        return $this->aturAktif($request, $lokasi);
    }

    public function destroy(Cabang $lokasi): RedirectResponse
    {
        return $this->hapusData($lokasi);
    }

    protected function bacaFilter(Request $request): array
    {
        $jenis = $request->query('jenis');

        return ['jenis' => array_key_exists((string) $jenis, Cabang::LABEL_JENIS) ? $jenis : ''];
    }

    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query
            ->when($filter['jenis'] !== '', fn (Builder $q) => $q->where('jenis', $filter['jenis']))
            ->withCount(['users as jumlah_pengguna' => fn (Builder $q) => $q->where('aktif', true)])
            ->orderByDesc('aktif')
            ->orderByRaw('jenis = ? desc', [Cabang::JENIS_PUSAT])
            ->orderBy('nama');
    }

    protected function dataDaftar(array $filter): array
    {
        return [
            'pilihanJenis' => Cabang::LABEL_JENIS,
            'adaPusatAktif' => Cabang::pusatAktifSelain(null) !== null,
        ];
    }

    protected function dataForm(?Model $item): array
    {
        return [
            'maks' => LokasiRequest::MAKS,
            'pilihanJenis' => Cabang::LABEL_JENIS,
            'jenisTerkunci' => $item !== null && $item->sudahDipakai(),
            'pusatLain' => Cabang::pusatAktifSelain($item?->id),
        ];
    }

    /**
     * - Mengaktifkan Gudang Pusat hanya boleh bila belum ada Gudang Pusat aktif lain.
     * - Menonaktifkan lokasi hanya boleh bila tidak ada pengguna aktif di lokasi itu.
     */
    protected function cegahUbahAktif(Model $item, bool $aktif): ?string
    {
        if ($aktif && $item->isPusat()) {
            $pusatLain = Cabang::pusatAktifSelain($item->id);

            if ($pusatLain) {
                return "Tidak bisa diaktifkan karena {$pusatLain->nama} masih menjadi Gudang Pusat aktif. "
                    .'Nonaktifkan Gudang Pusat itu terlebih dahulu.';
            }
        }

        if (! $aktif) {
            $jumlahPengguna = $item->users()->where('aktif', true)->count();

            if ($jumlahPengguna > 0) {
                return "Masih ada {$jumlahPengguna} pengguna aktif di {$item->nama}. "
                    .'Pindahkan atau nonaktifkan pengguna tersebut terlebih dahulu.';
            }
        }

        return null;
    }

    /** Lokasi yang masih ada stoknya atau dokumen yang belum selesai harus dibereskan dulu sebelum dihapus. */
    protected function cegahArsip(Model $item): ?string
    {
        $jumlah = DB::table('stok_saldo')->where('cabang_id', $item->id)->where('saldo', '!=', 0)->count();

        if ($jumlah > 0) {
            return "Masih ada stok {$jumlah} bahan di {$item->nama}. Kosongkan stoknya dulu (Stok → Kosongkan), lalu hapus lagi.";
        }

        if (StokMutasi::query()->menunggu()->where('cabang_id', $item->id)->exists()) {
            return "{$item->nama} masih punya dokumen yang menunggu persetujuan. Selesaikan dokumennya dulu.";
        }

        return null;
    }

}
