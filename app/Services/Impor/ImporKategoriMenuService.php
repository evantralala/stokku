<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\KategoriMenuRequest;
use App\Models\KategoriMenu;
use App\Support\ContohTemplat;

/** Impor & ekspor kategori menu. Urutan kategori baru mengikuti urutan baris di file. */
class ImporKategoriMenuService extends ImporMaster
{
    public function judul(): string
    {
        return 'Kategori menu';
    }

    protected function isiTemplat(): array
    {
        return array_map(fn (string $n) => ['nama' => $n], ContohTemplat::kategoriMenu());
    }

    public function kolom(): array
    {
        return [
            'nama' => ['lebar' => 24, 'ket' => 'Wajib. Nama kategori menu, mis. Ramen. Yang sudah ada dilewati.', 'contoh' => 'Ramen'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['nama'];
    }

    protected function jenisNama(): string
    {
        return 'nama:'.KamusImpor::KATEGORI_MENU;
    }

    protected function maksNama(): int
    {
        return KategoriMenuRequest::MAKS_NAMA;
    }

    protected function barisEkspor(): array
    {
        return KategoriMenu::query()->orderBy('urutan')->orderBy('nama')->pluck('nama')->map(fn ($n) => [$n])->all();
    }

    protected function namaAda(): array
    {
        return KategoriMenu::query()->tampil()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function namaArsip(): array
    {
        return KategoriMenu::query()->arsip()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function periksaBaris(array $isi): array
    {
        return ['data' => [], 'pesan' => []];
    }

    protected function simpanBaris(array $data): void
    {
        $this->buatAtauPulihkan(KategoriMenu::class, ['nama' => $data['nama'], 'urutan' => KategoriMenu::urutanBerikutnya(), 'aktif' => true]);
    }

    public function ringkasan(array $data): array
    {
        return [$data['nama'], ''];
    }
}
