<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Dasar controller halaman CRUD data master.
 *
 * Controller turunan cukup mengisi properti di bawah, lalu membuat action tipis
 * (index, create, store, edit, update, ubahAktif, destroy) yang memanggil method di sini.
 * Route-nya dibuat dengan Route::dataMaster() (lihat AppServiceProvider).
 *
 * View yang dibutuhkan: {$view}.index dan {$view}.form.
 * Model wajib memakai trait App\Models\Concerns\DataMaster.
 */
abstract class DataMasterController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** Awalan nama route, mis. 'admin-utama.satuan'. */
    protected string $rute;

    /** Folder view, mis. 'admin-utama.satuan'. */
    protected string $view;

    /** Nama data dalam huruf kecil untuk pesan, mis. 'satuan'. */
    protected string $label;

    protected int $perHalaman = 20;

    private const PILIHAN_STATUS = ['aktif', 'nonaktif', 'semua'];

    /* ------------------------------------------------------------------
     | Titik ubah untuk controller turunan
     * ------------------------------------------------------------------ */

    /** Filter tambahan dari query string, digabung dengan 'cari' dan 'status'. */
    protected function bacaFilter(Request $request): array
    {
        return [];
    }

    /** Tambahan query daftar: filter tambahan, relasi, kolom hitungan, urutan. */
    protected function sesuaikanDaftar(Builder $query, array $filter): void
    {
        $query->orderByDesc('aktif')->orderBy('nama');
    }

    /** Data tambahan untuk view daftar, mis. pilihan filter. */
    protected function dataDaftar(array $filter): array
    {
        return [];
    }

    /** Data tambahan untuk view form, mis. pilihan dropdown. */
    protected function dataForm(?Model $item): array
    {
        return [];
    }

    /** Pesan penolakan bila status aktif tidak boleh diubah, atau null bila boleh. */
    protected function cegahUbahAktif(Model $item, bool $aktif): ?string
    {
        return null;
    }

    /** Nilai bawaan saat data baru disimpan. */
    protected function nilaiBawaanBaru(): array
    {
        return ['aktif' => true];
    }

    /**
     * Ubah status aktif. Kembalikan pesan penolakan, atau null bila berhasil.
     * Bawaan: cek cegahUbahAktif() lalu simpan.
     */
    protected function terapkanAktif(Model $item, bool $aktif): ?string
    {
        $tolak = $this->cegahUbahAktif($item, $aktif);

        if ($tolak === null) {
            $item->update(['aktif' => $aktif]);
        }

        return $tolak;
    }

    /** Dipanggil di dalam transaksi yang sama setelah data baru dibuat, mis. untuk mencatat log. */
    protected function setelahDibuat(Model $item): void {}

    /** Dipanggil di dalam transaksi yang sama setelah data diubah. $sebelum = atribut mentah sebelum diubah. */
    protected function setelahDiubah(Model $item, array $sebelum): void {}

    /** Dipanggil di dalam transaksi yang sama setelah data dihapus permanen. */
    protected function setelahDihapus(Model $item): void {}

    /**
     * Pesan penolakan bila data yang sudah punya riwayat belum boleh diarsipkan (mis. bahan masih ada stoknya),
     * atau null bila boleh. Aturan nonaktif (cegahUbahAktif/terapkanAktif) tetap berlaku sesudahnya.
     */
    protected function cegahArsip(Model $item): ?string
    {
        return null;
    }

    /** Dipanggil di dalam transaksi yang sama setelah data diarsipkan. */
    protected function setelahDiarsipkan(Model $item): void {}

    /** Dipanggil di dalam transaksi yang sama setelah arsip dipulihkan karena data yang sama ditambahkan lagi. */
    protected function setelahDipulihkan(Model $item): void {}

    /* ------------------------------------------------------------------
     | Langkah CRUD yang dipanggil action controller turunan
     * ------------------------------------------------------------------ */

    protected function tampilkanDaftar(Request $request): View
    {
        $status = $request->query('status');

        $filter = [
            'cari'   => trim((string) $request->query('cari', '')),
            'status' => in_array($status, self::PILIHAN_STATUS, true) ? $status : 'aktif',
        ] + $this->bacaFilter($request);

        $query = $this->model::query()
            ->tampil()
            ->cari($filter['cari'])
            ->when($filter['status'] === 'aktif', fn (Builder $q) => $q->aktif())
            ->when($filter['status'] === 'nonaktif', fn (Builder $q) => $q->nonaktif());

        $this->sesuaikanDaftar($query, $filter);

        return view("{$this->view}.index", [
            'daftar'         => $query->paginate($this->perHalaman)->withQueryString(),
            'filter'         => $filter,
            'rute'           => $this->rute,
            'jumlahAktif'    => $this->model::query()->aktif()->count(),
            'jumlahNonaktif' => $this->model::query()->nonaktif()->count(),
        ] + $this->dataDaftar($filter));
    }

    protected function tampilkanForm(?Model $item = null): View
    {
        return view("{$this->view}.form", [
            'item'        => $item,
            'rute'        => $this->rute,
            'bisaDihapus' => $item !== null && ! $item->sudahDipakai(),
        ] + $this->dataForm($item));
    }

    /** Data baru disimpan; bila sama dengan data yang pernah dihapus (arsip), arsip itu dipulihkan dan riwayatnya tersambung lagi. */
    protected function simpanBaru(Request $request, array $data): RedirectResponse
    {
        $dipulihkan = false;

        $item = DB::transaction(function () use ($data, &$dipulihkan) {
            $arsip = $this->model::cariArsip($data);

            if ($arsip) {
                $arsip->forceFill(['dihapus_pada' => null])->fill($data + $this->nilaiBawaanBaru())->save();
                $this->setelahDipulihkan($arsip);
                $dipulihkan = true;

                return $arsip;
            }

            $item = $this->model::create($data + $this->nilaiBawaanBaru());
            $this->setelahDibuat($item);

            return $item;
        });

        $pesan = $dipulihkan
            ? $this->labelKapital()." {$item->namaTampilan()} ditambahkan kembali. Riwayat lamanya tersambung lagi."
            : $this->labelKapital()." {$item->namaTampilan()} berhasil ditambahkan.";

        if ($request->boolean('tambah_lagi')) {
            return self::tambahLagi($request, $this->rute, $pesan);
        }

        return redirect()->route($this->rute)->with('sukses', $pesan);
    }

    /**
     * Tombol "Simpan & tambah lagi": kembali ke form kosong untuk input berurutan.
     * Dari popup, daftar dimuat ulang lalu popup tambah dibuka lagi (session popup_buka); tanpa popup, ke halaman tambah.
     */
    public static function tambahLagi(Request $request, string $rute, string $pesan): RedirectResponse
    {
        $tambah = route("{$rute}.tambah", [], false);

        if ($request->filled('_popup')) {
            return redirect()->route($rute)->with('sukses', $pesan)->with('popup_buka', $tambah);
        }

        return redirect()->to($tambah)->with('sukses', $pesan);
    }

    protected function simpanPerubahan(Model $item, array $data): RedirectResponse
    {
        $sebelum = $item->getAttributes();

        DB::transaction(function () use ($item, $data, $sebelum) {
            $item->update($data);
            $this->setelahDiubah($item, $sebelum);
        });

        return redirect()
            ->route($this->rute)
            ->with('sukses', "Perubahan {$this->label} {$item->namaTampilan()} berhasil disimpan.");
    }

    protected function aturAktif(Request $request, Model $item): RedirectResponse
    {
        $data = $request->validate(
            ['aktif' => ['required', 'boolean']],
            ['aktif.*' => 'Status aktif tidak valid. Muat ulang halaman lalu coba lagi.']
        );

        $tolak = $this->terapkanAktif($item, (bool) $data['aktif']);

        if ($tolak !== null) {
            return redirect()->route("{$this->rute}.ubah", $item)->with('galat', $tolak);
        }

        $pesan = $item->aktif
            ? $this->labelKapital()." {$item->namaTampilan()} diaktifkan kembali."
            : $this->labelKapital()." {$item->namaTampilan()} dinonaktifkan. Riwayatnya tetap tersimpan.";

        return redirect()->route($this->rute)->with('sukses', $pesan);
    }

    /**
     * Hapus: data yang belum pernah dipakai dihapus permanen; yang sudah punya riwayat diarsipkan (hilang dari daftar
     * dan pilihan, laporan dan kartu stok lama tetap menampilkan namanya). Kembali ke daftar.
     */
    protected function hapusData(Model $item): RedirectResponse
    {
        $nama = $item->namaTampilan();
        $daftar = redirect()->route($this->rute);

        if (! $item->sudahDipakai()) {
            try {
                DB::transaction(function () use ($item) {
                    $item->delete();
                    $this->setelahDihapus($item);
                });

                return $daftar->with('sukses', $this->labelKapital()." {$nama} dihapus.");
            } catch (QueryException) {
                // Ada relasi yang belum tercakup sudahDipakai(): diarsipkan saja.
            }
        }

        $tolak = $this->cegahArsip($item) ?? ($item->aktif ? $this->cegahUbahAktif($item, false) : null);

        if ($tolak !== null) {
            return $daftar->with('galat', $tolak);
        }

        $tolak = DB::transaction(function () use ($item) {
            if ($item->aktif && ($tolak = $this->terapkanAktif($item, false)) !== null) {
                return $tolak;
            }

            $item->forceFill(['aktif' => false, 'dihapus_pada' => now()])->save();
            $this->setelahDiarsipkan($item);

            return null;
        });

        if ($tolak !== null) {
            return $daftar->with('galat', $tolak);
        }

        return $daftar->with('sukses', $this->labelKapital()." {$nama} dihapus. Riwayat dan laporan lamanya tetap ada; tambahkan lagi dengan nama yang sama bila ingin dipakai kembali.");
    }

    private function labelKapital(): string
    {
        return Str::ucfirst($this->label);
    }
}
