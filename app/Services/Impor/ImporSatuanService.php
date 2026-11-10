<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\SatuanRequest;
use App\Models\Satuan;
use App\Support\ContohTemplat;

/** Impor & ekspor satuan. Satu baris = satu satuan. */
class ImporSatuanService extends ImporMaster
{
    public function judul(): string
    {
        return 'Satuan';
    }

    protected function isiTemplat(): array
    {
        return array_map(fn (string $n) => ['nama' => $n], ContohTemplat::SATUAN);
    }

    public function kolom(): array
    {
        return [
            'nama' => ['lebar' => 20, 'ket' => 'Wajib. Nama satuan, mis. gram, kg, pcs. Yang sudah ada dilewati.', 'contoh' => 'kg'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['nama'];
    }

    protected function jenisNama(): string
    {
        return 'nama:'.KamusImpor::SATUAN;
    }

    protected function maksNama(): int
    {
        return SatuanRequest::MAKS_NAMA;
    }

    protected function barisEkspor(): array
    {
        return Satuan::query()->orderBy('nama')->pluck('nama')->map(fn ($n) => [$n])->all();
    }

    protected function namaAda(): array
    {
        return Satuan::query()->tampil()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function namaArsip(): array
    {
        return Satuan::query()->arsip()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->all();
    }

    protected function periksaBaris(array $isi): array
    {
        return ['data' => [], 'pesan' => []];
    }

    protected function simpanBaris(array $data): void
    {
        $this->buatAtauPulihkan(Satuan::class, ['nama' => $data['nama'], 'aktif' => true]);
    }

    public function ringkasan(array $data): array
    {
        return [$data['nama'], ''];
    }
}
