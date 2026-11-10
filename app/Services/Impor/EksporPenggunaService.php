<?php

namespace App\Services\Impor;

use App\Models\User;

/** Ekspor daftar pengguna (tanpa password). */
class EksporPenggunaService extends EksporSaja
{
    public function judul(): string
    {
        return 'Pengguna';
    }

    public function kolom(): array
    {
        return [
            'nama'   => ['lebar' => 24, 'ket' => 'Nama pengguna.'],
            'email'  => ['lebar' => 30, 'ket' => 'Email untuk masuk.'],
            'peran'  => ['lebar' => 16, 'ket' => 'Peran.'],
            'lokasi' => ['lebar' => 24, 'ket' => 'Lokasi kerja (kosong untuk Admin Utama).'],
            'status' => ['lebar' => 12, 'ket' => 'Aktif atau tidak aktif.'],
        ];
    }

    protected function barisEkspor(): array
    {
        return User::query()->with('cabang:id,nama')->orderBy('nama')->get()
            ->map(fn (User $u) => [$u->nama, $u->email, $u->label_role, $u->cabang?->nama, $u->aktif ? 'Aktif' : 'Tidak Aktif'])
            ->all();
    }
}
