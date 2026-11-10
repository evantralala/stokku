<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengosongkan semua data transaksi untuk Tutup Buku (PeriodeBukuService): stok, kartu stok, dokumen
 * (permintaan, kiriman, barang masuk/rusak, penjualan, opname, persetujuan, impor, tambah/ubah stok),
 * dan notifikasi. Data master, akun, pengaturan, log, dan daftar periode tetap.
 *
 * Tabel yang tidak disebut di TABEL_TETAP dianggap data transaksi, sehingga tabel transaksi baru ikut
 * dibersihkan tanpa perlu mengubah daftar ini.
 */
class PengosongTransaksiService
{
    /** Tidak pernah dikosongkan saat tutup buku. */
    public const TABEL_TETAP = [
        'migrations', 'users', 'pengaturan', 'kontrol_sistem', 'sessions', 'password_reset_tokens',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'bahan_baku', 'bahan_baku_minimum', 'resep',
        'cabang', 'satuan', 'kategori', 'kategori_menu', 'menu',
        'log_perubahan', 'periode_buku', 'email_laporan',
    ];

    public function kosongkan(): void
    {
        $tabel = $this->tabelTransaksi();
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($this->urutanHapus($tabel) as $t) {
                $mysql ? DB::table($t)->truncate() : DB::table($t)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @return list<string> tabel transaksi di database aplikasi ini, urut nama */
    public function tabelTransaksi(): array
    {
        // Hanya tabel di database aplikasi ini (tanpa skema, MySQL ikut mendaftar tabel database lain di server).
        return collect(Schema::getTables(Schema::getCurrentSchemaName()))
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->reject(fn (string $n) => in_array($n, self::TABEL_TETAP, true) || str_starts_with($n, 'sqlite_'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Urutan hapus: tabel yang merujuk tabel lain dihapus lebih dulu, supaya aman juga bila pemeriksaan
     * foreign key tidak bisa dimatikan (mis. SQLite di dalam transaksi).
     *
     * @param  list<string>  $tabel
     * @return list<string>
     */
    private function urutanHapus(array $tabel): array
    {
        $rujukan = [];

        foreach ($tabel as $t) {
            $rujukan[$t] = collect(Schema::getForeignKeys($t))
                ->pluck('foreign_table')
                ->filter(fn ($f) => $f !== $t && in_array($f, $tabel, true))
                ->unique()
                ->values()
                ->all();
        }

        $urutan = [];

        while ($rujukan !== []) {
            $masihDirujuk = array_merge(...array_values($rujukan));
            $siap = array_values(array_filter(array_keys($rujukan), fn ($t) => ! in_array($t, $masihDirujuk, true)));

            if ($siap === []) {
                $siap = array_keys($rujukan);
            }

            foreach ($siap as $t) {
                $urutan[] = $t;
                unset($rujukan[$t]);
            }
        }

        return $urutan;
    }
}
