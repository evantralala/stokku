<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Perilaku bersama data master yang dikelola lewat halaman CRUD:
 * status aktif, pencarian, nama tampilan, dan aturan hapus.
 *
 * Hapus: data yang belum pernah dipakai dihapus permanen; yang sudah punya riwayat diarsipkan (dihapus_pada diisi,
 * aktif = false) sehingga hilang dari daftar dan pilihan tetapi riwayat lama tetap utuh. Menambah lagi data dengan
 * nama/kunci unik yang sama memulihkan arsip itu (kolomUnik()).
 *
 * Model pemakai wajib punya kolom `aktif` dan `dihapus_pada`, dan mengisi sudahDipakai().
 * Kolom pencarian bisa diubah dengan menimpa kolomCari().
 */
trait DataMaster
{
    /** Sudah dipakai di transaksi atau data lain? Jika ya, hanya boleh dinonaktifkan. */
    abstract public function sudahDipakai(): bool;

    /** @return list<string> */
    public function kolomCari(): array
    {
        return ['nama'];
    }

    /** Nama yang ditampilkan di pesan, mis. "Satuan kg berhasil disimpan." */
    public function namaTampilan(): string
    {
        return (string) $this->nama;
    }

    public function scopeAktif(Builder $query): void
    {
        $query->where($this->qualifyColumn('aktif'), true);
    }

    /** Nonaktif tetapi belum dihapus (arsip tidak termasuk). */
    public function scopeNonaktif(Builder $query): void
    {
        $query->where($this->qualifyColumn('aktif'), false)->whereNull($this->qualifyColumn('dihapus_pada'));
    }

    /** Belum dihapus (arsip tidak termasuk). */
    public function scopeTampil(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('dihapus_pada'));
    }

    public function scopeArsip(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('dihapus_pada'));
    }

    public function diarsipkan(): bool
    {
        return $this->dihapus_pada !== null;
    }

    /** @return list<string> kolom unik untuk mengenali arsip saat data yang sama ditambahkan lagi */
    public function kolomUnik(): array
    {
        return ['nama'];
    }

    /** Arsip yang punya nilai kolom unik sama (huruf besar-kecil diabaikan), atau null. */
    public static function cariArsip(array $data): ?static
    {
        $model = new static;
        $kolom = array_filter($model->kolomUnik(), fn (string $k) => filled($data[$k] ?? null));

        if ($kolom === []) {
            return null;
        }

        return static::query()->arsip()
            ->where(function (Builder $q) use ($kolom, $data) {
                foreach ($kolom as $k) {
                    $q->orWhereRaw('LOWER('.$k.') = ?', [mb_strtolower((string) $data[$k])]);
                }
            })
            ->first();
    }

    public function scopeCari(Builder $query, ?string $kata): void
    {
        $kata = trim((string) $kata);

        if ($kata === '') {
            return;
        }

        // % dan _ diperlakukan sebagai huruf biasa, bukan wildcard LIKE.
        $pola = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kata).'%';

        $query->where(function (Builder $q) use ($pola) {
            foreach ($this->kolomCari() as $kolom) {
                $q->orWhere($this->qualifyColumn($kolom), 'like', $pola);
            }
        });
    }
}
