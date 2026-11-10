<?php

namespace App\Observers;

use App\Models\LogPerubahan;
use App\Services\LogPerubahanService;
use App\Support\Angka;
use App\Support\DaftarRiwayat;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Mencatat tambah, ubah, dan hapus data master oleh pengguna yang sedang login ke log perubahan (kategori Data master),
 * lengkap dengan nilai lama dan baru. Perubahan tanpa pengguna (seeder, jadwal) tidak dicatat;
 * impor Excel dicatat sebagai satu ringkasan oleh layanannya sendiri (lewat diam()).
 */
class CatatPerubahanData
{
    private static bool $diam = false;

    public function __construct(private LogPerubahanService $log)
    {
    }

    /** Jalankan tanpa mencatat perubahan satu per satu. */
    public static function diam(Closure $kerja): mixed
    {
        $sebelum = self::$diam;
        self::$diam = true;

        try {
            return $kerja();
        } finally {
            self::$diam = $sebelum;
        }
    }

    public function created(Model $model): void
    {
        $this->catat($model, 'tambah', 'Ditambah', $this->rincian($model, [], $this->kolom($model, $model->getAttributes())));
    }

    public function updated(Model $model): void
    {
        $perubahan = $model->getChanges();
        $berubah = $this->kolom($model, $perubahan);

        if (array_key_exists('dihapus_pada', $perubahan)) {
            $berubah = array_diff_key($berubah, ['dihapus_pada' => true, 'aktif' => true]);
            $this->catat($model, $model->dihapus_pada ? 'hapus' : 'tambah', $model->dihapus_pada ? 'Dihapus (riwayat disimpan)' : 'Ditambahkan kembali',
                $this->rincian($model, array_intersect_key($model->getOriginal(), $berubah), $berubah));

            return;
        }

        if ($berubah === []) {
            return;
        }

        $lama = array_intersect_key($model->getOriginal(), $berubah);
        $judul = array_key_exists('aktif', $berubah) && count($berubah) === 1
            ? ($model->aktif ? 'Diaktifkan' : 'Dinonaktifkan')
            : 'Diubah';

        $this->catat($model, 'ubah', $judul, $this->rincian($model, $lama, $berubah));
    }

    public function deleted(Model $model): void
    {
        $this->catat($model, 'hapus', 'Dihapus', []);
    }

    private function catat(Model $model, string $aksi, string $judul, array $rincian): void
    {
        if (self::$diam || ! Auth::check()) {
            return;
        }

        $jenis = DaftarRiwayat::NAMA_DATA[$model::class] ?? class_basename($model);

        $this->log->catat(
            Auth::user(),
            LogPerubahan::KATEGORI_DATA,
            'data.'.$aksi,
            "{$judul}: {$jenis} ".($model->nama ?? '#'.$model->getKey()),
            $rincian,
            $model,
        );
    }

    /** Kolom yang dicatat dari daftar nilai [kolom => nilai]. */
    private function kolom(Model $model, array $nilai): array
    {
        return array_diff_key($nilai, array_flip(DaftarRiwayat::KOLOM_ABAIKAN));
    }

    /** @return list<array{label: string, lama: ?string, baru: ?string}> */
    private function rincian(Model $model, array $lama, array $baru): array
    {
        $hasil = [];

        foreach ($baru as $kolom => $nilai) {
            $teksLama = array_key_exists($kolom, $lama) ? $this->teks($kolom, $lama[$kolom]) : null;
            $teksBaru = $this->teks($kolom, $nilai);

            if ($kolom === 'foto' && $teksLama !== null && $teksBaru !== null) {
                $teksBaru = 'Diganti';
            }

            if ($teksLama === $teksBaru) {
                continue;
            }

            $hasil[] = [
                'label' => DaftarRiwayat::LABEL_KOLOM[$kolom] ?? ucfirst(str_replace('_', ' ', $kolom)),
                'lama'  => $teksLama,
                'baru'  => $teksBaru,
            ];
        }

        return $hasil;
    }

    private function teks(string $kolom, mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (isset(DaftarRiwayat::RUJUKAN[$kolom])) {
            return (string) (DaftarRiwayat::RUJUKAN[$kolom]::query()->whereKey($nilai)->value('nama') ?? '#'.$nilai);
        }

        if ($kolom === 'foto') {
            return 'Ada';
        }

        if ($kolom === 'aktif') {
            return (bool) $nilai ? 'Aktif' : 'Nonaktif';
        }

        if (is_bool($nilai)) {
            return (bool) $nilai ? 'Ya' : 'Tidak';
        }

        return is_numeric($nilai) && ! is_string($nilai) || (is_string($nilai) && preg_match('/^-?\d+\.\d+$/', $nilai))
            ? Angka::tampil($nilai)
            : (string) $nilai;
    }
}
