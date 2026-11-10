<?php

namespace App\Services\Impor;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Data master pendukung impor (kategori, satuan, kategori menu) yang dicari dari namanya
 * dan dibuat otomatis bila belum ada. Nama dibandingkan tanpa membedakan huruf besar-kecil.
 */
final class KumpulanMaster
{
    /** @var Collection<string, Model> */
    private Collection $isi;

    private int $baru = 0;

    /**
     * @param  class-string<Model>  $kelas
     * @param  Closure(string): Model  $buat
     */
    public function __construct(string $kelas, private Closure $buat)
    {
        $this->isi = $kelas::query()->get()->keyBy(fn (Model $m) => mb_strtolower($m->nama));
    }

    public function cari(string $nama): ?Model
    {
        return $this->isi->get(mb_strtolower($nama));
    }

    /** Ambil, atau buat bila belum ada. Data yang pernah dihapus (arsip) dipulihkan. */
    public function ambil(string $nama): Model
    {
        $ada = $this->cari($nama);

        if ($ada && $ada->dihapus_pada !== null) {
            $ada->forceFill(['dihapus_pada' => null, 'aktif' => true])->save();
            $this->baru++;
        }

        if (! $this->cari($nama)) {
            $this->isi->put(mb_strtolower($nama), ($this->buat)($nama));
            $this->baru++;
        }

        return $this->cari($nama);
    }

    public function jumlahBaru(): int
    {
        return $this->baru;
    }

    /**
     * Pesan galat untuk nama kosong, terlalu panjang, atau nonaktif. Nama yang belum ada dicatat sebagai baru
     * ("$labelBaru baru: nama", bawaan memakai $label).
     *
     * @param  list<string>  $catatan
     * @return list<string>
     */
    public function periksa(string $nama, string $label, int $maks, array &$catatan, ?string $labelBaru = null): array
    {
        if ($nama === '') {
            return [$label.' wajib diisi.'];
        }

        if (mb_strlen($nama) > $maks) {
            return [$label.' maksimal '.$maks.' huruf.'];
        }

        $ada = $this->cari($nama);

        if ($ada && $ada->dihapus_pada !== null) {
            $catatan[] = ($labelBaru ?? $label).' ditambahkan kembali: '.$ada->nama;

            return [];
        }

        if ($ada && ! $ada->aktif) {
            return [$label.' '.$ada->nama.' sedang nonaktif. Aktifkan dulu di data master.'];
        }

        if (! $ada) {
            $catatan[] = ($labelBaru ?? $label).' baru: '.$nama;
        }

        return [];
    }
}
