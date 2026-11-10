<?php

namespace App\Services\Impor;

use App\Models\Cabang;

/** Ekspor daftar lokasi (Gudang Pusat dan cabang). */
class EksporLokasiService extends EksporSaja
{
    public function judul(): string
    {
        return 'Lokasi';
    }

    public function kolom(): array
    {
        return [
            'kode'              => ['lebar' => 8, 'ket' => 'Kode lokasi (dipakai di nomor dokumen).'],
            'nama'              => ['lebar' => 26, 'ket' => 'Nama lokasi.'],
            'jenis'             => ['lebar' => 14, 'ket' => 'Gudang Pusat atau cabang.'],
            'alamat'            => ['lebar' => 34, 'ket' => 'Alamat.'],
            'status'            => ['lebar' => 12, 'ket' => 'Aktif atau tidak aktif.'],
        ];
    }

    protected function barisEkspor(): array
    {
        return Cabang::query()->orderByDesc('jenis')->orderBy('nama')->get()
            ->map(fn (Cabang $c) => [$c->kode, $c->nama, $c->label_jenis, $c->alamat, $c->aktif ? 'Aktif' : 'Tidak Aktif'])
            ->all();
    }
}
