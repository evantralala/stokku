<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Membuat notifikasi untuk sekelompok pengguna.
 * Dipanggil dari service transaksi di dalam transaksi yang sama, sehingga notifikasi ikut batal bila transaksi gagal.
 */
class NotifikasiService
{
    /** Kirim ke semua Admin Utama aktif. */
    public function keAdminUtama(string $judul, ?string $keterangan = null, ?string $tautan = null): int
    {
        return $this->kirim(User::query()->aktif()->where('role', User::ROLE_ADMIN_UTAMA)->get(), $judul, $keterangan, $tautan);
    }

    /** Kirim ke semua admin aktif yang bertugas di lokasi itu (Gudang Pusat atau cabang). */
    public function keLokasi(Cabang|int $lokasi, string $judul, ?string $keterangan = null, ?string $tautan = null): int
    {
        $lokasiId = $lokasi instanceof Cabang ? $lokasi->id : $lokasi;

        return $this->kirim(User::query()->aktif()->where('cabang_id', $lokasiId)->get(), $judul, $keterangan, $tautan);
    }

    /** Kirim ke admin aktif Gudang Pusat. */
    public function kePusat(string $judul, ?string $keterangan = null, ?string $tautan = null): int
    {
        $pusat = Cabang::pusatAktifSelain(null);

        return $pusat ? $this->keLokasi($pusat, $judul, $keterangan, $tautan) : 0;
    }

    /** Awalan nama route untuk admin di lokasi itu: "pusat" atau "cabang". */
    public static function awalanRute(Cabang $lokasi): string
    {
        return $lokasi->isPusat() ? 'pusat' : 'cabang';
    }

    /**
     * Tandai dibaca semua notifikasi yang belum dibaca dengan tautan itu, untuk semua penerima.
     * Dipanggil saat hal yang diberitahukan sudah tuntas (kiriman diterima, dokumen diputuskan),
     * supaya lonceng tidak terus menunjukkan pekerjaan yang sudah selesai.
     */
    public function selesaikan(string ...$tautan): int
    {
        return Notifikasi::query()
            ->whereIn('tautan', $tautan)
            ->belumDibaca()
            ->update(['dibaca_pada' => now(), 'updated_at' => now()]);
    }

    /**
     * Tandai dibaca notifikasi milik pengguna yang tautannya menuju halaman yang sedang ia buka.
     *
     * @param  list<string>  $tautan
     */
    public function bacaHalaman(User $pengguna, array $tautan): int
    {
        return Notifikasi::query()
            ->milik($pengguna->id)
            ->whereIn('tautan', $tautan)
            ->belumDibaca()
            ->update(['dibaca_pada' => now(), 'updated_at' => now()]);
    }

    /** @param  Collection<int, User>  $penerima */
    public function kirim(Collection $penerima, string $judul, ?string $keterangan = null, ?string $tautan = null): int
    {
        $sekarang = now();

        $baris = $penerima->unique('id')->map(fn (User $u) => [
            'user_id'    => $u->id,
            'judul'      => Str::limit($judul, Notifikasi::MAKS_JUDUL, ''),
            'keterangan' => $keterangan === null ? null : Str::limit($keterangan, Notifikasi::MAKS_KETERANGAN - 3),
            'tautan'     => $tautan,
            'created_at' => $sekarang,
            'updated_at' => $sekarang,
        ])->values()->all();

        if ($baris !== []) {
            Notifikasi::insert($baris);
        }

        return count($baris);
    }
}
