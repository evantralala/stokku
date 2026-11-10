<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\KategoriRequest;
use App\Models\Kategori;
use App\Support\ContohTemplat;

/** Impor & ekspor kategori bahan. Urutan kategori baru mengikuti urutan baris di file. */
class ImporKategoriService extends ImporMaster
{
    public function judul(): string
    {
        return 'Kategori bahan';
    }

    protected function isiTemplat(): array
    {
        return array_map(fn (string $n) => ['nama' => $n], ContohTemplat::kategoriBahan());
    }

    public function kolom(): array
    {
        return [
            'nama' => ['lebar' => 24, 'ket' => 'Wajib. Nama kategori, mis. Sayur. Yang sudah ada dilewati.', 'contoh' => 'Sayur'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['nama'];
    }

    protected function jenisNama(): string
    {
        return 'nama:'.KamusImpor::KATEGORI;
    }

    protected function maksNama(): int
    {
        return KategoriRequest::MAKS_NAMA;
    }

    protected function barisEkspor(): array
    {
        return Kategori::query()->orderBy('urutan')->orderBy('nama')->get()
            ->map(fn (Kategori $k) => [$k->nama])
            ->all();
    }

    protected function namaAda(): array
    {
        return Kategori::query()->tampil()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function namaArsip(): array
    {
        return Kategori::query()->arsip()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function periksaBaris(array $isi): array
    {
        return ['data' => [], 'pesan' => []];
    }

    protected function simpanBaris(array $data): void
    {
        $this->buatAtauPulihkan(Kategori::class, [
            'nama'   => $data['nama'],
            'urutan' => Kategori::urutanBerikutnya(),
            'aktif'  => true,
        ]);
    }

    public function ringkasan(array $data): array
    {
        return [$data['nama'], ''];
    }
}
