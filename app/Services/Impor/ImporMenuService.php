<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\KategoriMenuRequest;
use App\Http\Requests\AdminUtama\MenuRequest;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Support\ContohTemplat;
use Illuminate\Support\Str;

/**
 * Impor & ekspor daftar menu, tanpa resep (resep diisi manual atau lewat impor resep).
 * Kategori menu yang belum ada dibuat otomatis.
 */
class ImporMenuService extends ImporMaster
{
    private KumpulanMaster $kategori;

    public function judul(): string
    {
        return 'Menu';
    }

    protected function isiTemplat(): array
    {
        return array_map(fn (array $m) => ['nama' => $m[0], 'kategori_menu' => $m[1]], ContohTemplat::MENU);
    }

    public function kolom(): array
    {
        return [
            'nama'          => ['lebar' => 28, 'ket' => 'Wajib. Nama menu, mis. Shoyu Ramen. Yang sudah ada dilewati.', 'contoh' => 'Shoyu Ramen'],
            'kategori_menu' => ['lebar' => 18, 'ket' => 'Wajib. Nama kategori menu, mis. Ramen. Yang belum ada dibuat otomatis.', 'contoh' => 'Ramen'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['nama', 'kategori_menu'];
    }

    protected function jenisNama(): string
    {
        return 'nama:'.KamusImpor::MENU;
    }

    protected function maksNama(): int
    {
        return MenuRequest::MAKS['nama'];
    }

    protected function barisEkspor(): array
    {
        return Menu::query()->with('kategoriMenu:id,nama')->orderBy('nama')->get()
            ->map(fn (Menu $m) => [$m->nama, $m->kategoriMenu->nama])
            ->all();
    }

    protected function namaAda(): array
    {
        return Menu::query()->tampil()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function namaArsip(): array
    {
        return Menu::query()->arsip()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function siapkan(): void
    {
        $this->kategori = new KumpulanMaster(KategoriMenu::class, fn (string $nama) => KategoriMenu::create([
            'nama' => $nama, 'urutan' => KategoriMenu::urutanBerikutnya(), 'aktif' => true,
        ]));
    }

    protected function periksaBaris(array $isi): array
    {
        $catatan = [];
        $kategori = Str::squish((string) $isi['kategori_menu']);

        return [
            'data'    => ['kategori_menu' => $kategori],
            'pesan'   => $this->kategori->periksa($kategori, 'Kategori menu', KategoriMenuRequest::MAKS_NAMA, $catatan),
            'catatan' => $catatan,
        ];
    }

    protected function simpanBaris(array $data): void
    {
        $this->buatAtauPulihkan(Menu::class, [
            'nama'             => $data['nama'],
            'kategori_menu_id' => $this->kategori->ambil($data['kategori_menu'])->id,
            'aktif'            => true,
        ]);
    }

    protected function catatanSetelahSimpan(): array
    {
        return $this->kategori->jumlahBaru() ? [$this->kategori->jumlahBaru().' kategori menu baru'] : [];
    }

    public function ringkasan(array $data): array
    {
        return [$data['nama'], $data['kategori_menu'].' · resep belum diisi'];
    }
}
