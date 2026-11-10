<?php

namespace App\Services;

use App\Support\Identitas;
use App\Models\LogPerubahan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Cadangan database untuk arsip tutup buku (PeriodeBukuService), dalam bentuk file .sql.gz (skema + isi), dibuat dengan PHP saja
 * supaya jalan di shared hosting tanpa mysqldump. File bisa diimpor lewat phpMyAdmin.
 * Disimpan di disk local (storage/app/private/cadangan), tidak bisa dibuka langsung dari web.
 */
class CadanganService
{
    public const DISK = 'local';
    public const FOLDER = 'cadangan';

    /** Jumlah file cadangan terbaru yang disimpan; yang lebih lama dihapus otomatis. */
    public const SIMPAN = 14;

    /** Tabel sementara yang tidak perlu dicadangkan. */
    public const TABEL_DILEWATI = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const BARIS_PER_INSERT = 200;

    private const POLA_NAMA = '/^cadangan-\d{8}-\d{6}\.sql\.gz$/';

    public function __construct(private LogPerubahanService $log)
    {
    }

    /** Buat satu file cadangan baru, rapikan yang lama, dan kembalikan namanya. */
    public function buat(?User $oleh = null): string
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::FOLDER);

        $nama = 'cadangan-'.now()->format('Ymd-His').'.sql.gz';
        $jalur = $disk->path(self::FOLDER.'/'.$nama);
        $gz = gzopen($jalur, 'wb6');

        if ($gz === false) {
            throw new DomainException('File cadangan tidak bisa dibuat. Periksa izin folder storage.');
        }

        try {
            $this->tulis($gz);
        } finally {
            gzclose($gz);
        }

        $this->rapikan();
        $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'cadangan.buat', 'Cadangan database dibuat: '.$nama);

        return $nama;
    }

    /** @return Collection<int, array{nama: string, ukuran: int, waktu: Carbon}> terbaru dulu */
    public function daftar(): Collection
    {
        $disk = Storage::disk(self::DISK);

        return collect($disk->files(self::FOLDER))
            ->map(fn (string $f) => basename($f))
            ->filter(fn (string $nama) => preg_match(self::POLA_NAMA, $nama) === 1)
            ->map(fn (string $nama) => [
                'nama'   => $nama,
                'ukuran' => $disk->size(self::FOLDER.'/'.$nama),
                'waktu'  => Carbon::createFromTimestamp($disk->lastModified(self::FOLDER.'/'.$nama)),
            ])
            ->sortByDesc('nama')
            ->values();
    }

    /** Jalur lengkap file cadangan untuk diunduh, atau null bila nama tidak valid / tidak ada. */
    public function jalur(string $nama): ?string
    {
        $relatif = self::FOLDER.'/'.$nama;

        if (preg_match(self::POLA_NAMA, $nama) !== 1 || ! Storage::disk(self::DISK)->exists($relatif)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($relatif);
    }

    public function catatUnduh(User $oleh, string $nama): void
    {
        $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'cadangan.unduh', 'Cadangan database diunduh: '.$nama);
    }

    private function rapikan(): void
    {
        $disk = Storage::disk(self::DISK);

        $this->daftar()->slice(self::SIMPAN)->each(fn (array $f) => $disk->delete(self::FOLDER.'/'.$f['nama']));
    }

    /** @param  resource  $gz */
    private function tulis($gz): void
    {
        $koneksi = DB::connection();
        $driver = $koneksi->getDriverName();
        $pdo = $koneksi->getPdo();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new DomainException('Cadangan otomatis hanya mendukung MySQL/MariaDB.');
        }

        $mysql = $driver !== 'sqlite';
        $kutip = fn (string $nama) => $mysql ? '`'.str_replace('`', '``', $nama).'`' : '"'.str_replace('"', '""', $nama).'"';

        gzwrite($gz, '-- Cadangan '.Identitas::aplikasi().' · '.now()->toDateTimeString()."\n");
        gzwrite($gz, '-- Impor lewat phpMyAdmin ke database kosong.'."\n\n");
        if ($mysql) {
            gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        }

        // Hanya tabel di database aplikasi ini (tanpa skema, MySQL ikut mendaftar tabel database lain di server).
        $tabel = collect(Schema::getTables(Schema::getCurrentSchemaName()))
            ->pluck('name')
            ->reject(fn (string $t) => in_array($t, self::TABEL_DILEWATI, true) || str_starts_with($t, 'sqlite_'))
            ->sort()
            ->values();

        foreach ($tabel as $t) {
            $buat = $mysql
                ? (array) $koneksi->selectOne('SHOW CREATE TABLE '.$kutip($t))
                : ['sql' => $koneksi->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$t])->sql];

            gzwrite($gz, 'DROP TABLE IF EXISTS '.$kutip($t).";\n");
            gzwrite($gz, ($buat['Create Table'] ?? $buat['sql']).";\n\n");

            // Kolom hasil hitungan (mis. opname_detail.selisih) dibuat ulang oleh database, jadi tidak ikut diisi.
            $namaKolom = collect(Schema::getColumns($t))->whereNull('generation')->pluck('name')->all();
            $kolom = implode(', ', array_map($kutip, $namaKolom));
            $kumpulan = [];

            foreach ($koneksi->table($t)->select($namaKolom)->cursor() as $baris) {
                $baris = (array) $baris;
                $kumpulan[] = '('.implode(', ', array_map(
                    fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)),
                    $baris,
                )).')';

                if (count($kumpulan) >= self::BARIS_PER_INSERT) {
                    gzwrite($gz, 'INSERT INTO '.$kutip($t).' ('.$kolom.') VALUES'."\n".implode(",\n", $kumpulan).";\n");
                    $kumpulan = [];
                }
            }

            if ($kumpulan !== []) {
                gzwrite($gz, 'INSERT INTO '.$kutip($t).' ('.$kolom.') VALUES'."\n".implode(",\n", $kumpulan).";\n");
            }

            gzwrite($gz, "\n");
        }

        if ($mysql) {
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        }
    }
}
