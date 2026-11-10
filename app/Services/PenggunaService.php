<?php

namespace App\Services;

use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Aturan akun pengguna (aktif/nonaktif, password) beserta catatan di log perubahan. */
class PenggunaService
{
    /** Kolom pengguna yang dicatat di log beserta labelnya di layar. */
    private const LABEL_KOLOM = [
        'nama'      => 'Nama',
        'email'     => 'Email',
        'role'      => 'Peran',
        'cabang_id' => 'Lokasi',
    ];

    public function __construct(private LogPerubahanService $log) {}

    /**
     * Alasan penolakan bila status aktif tidak boleh diubah, atau null bila boleh.
     * - Akun sendiri tidak bisa dinonaktifkan.
     * - Admin Utama aktif terakhir tidak bisa dinonaktifkan.
     * - Pengguna yang lokasinya nonaktif tidak bisa diaktifkan sebelum dipindah.
     */
    public function alasanTolakAktif(User $pengguna, bool $aktif, User $oleh): ?string
    {
        if (! $aktif && $pengguna->is($oleh)) {
            return 'Anda tidak bisa menonaktifkan akun sendiri.';
        }

        if (! $aktif && $pengguna->isAdminUtama() && ! User::adaAdminUtamaAktifLain($pengguna->id)) {
            return 'Harus tetap ada minimal satu Admin Utama aktif.';
        }

        if ($aktif && $pengguna->cabang_id && ! $pengguna->cabang()->value('aktif')) {
            return 'Lokasi pengguna ini sudah tidak aktif. Ubah lokasinya terlebih dahulu.';
        }

        return null;
    }

    /**
     * Nyalakan atau matikan akun. Akun yang dimatikan langsung keluar pada request berikutnya.
     *
     * @throws DomainException
     */
    public function aturAktif(User $pengguna, bool $aktif, User $oleh): void
    {
        $tolak = $this->alasanTolakAktif($pengguna, $aktif, $oleh);

        if ($tolak !== null) {
            throw new DomainException($tolak);
        }

        if ($pengguna->aktif === $aktif) {
            return;
        }

        DB::transaction(function () use ($pengguna, $aktif, $oleh) {
            $pengguna->update(['aktif' => $aktif]);

            $this->log->catat(
                $oleh,
                LogPerubahan::KATEGORI_AKUN,
                $aktif ? 'akun.nyala' : 'akun.mati',
                ($aktif ? 'Akun dinyalakan: ' : 'Akun dimatikan: ').$pengguna->nama,
                [],
                $pengguna,
            );
        });
    }

    public function catatDibuat(User $pengguna, User $oleh): void
    {
        $this->log->catat(
            $oleh,
            LogPerubahan::KATEGORI_PENGGUNA,
            'pengguna.tambah',
            "Pengguna ditambahkan: {$pengguna->nama}",
            LogPerubahanService::rincian([], $this->teksTampilan($pengguna->getAttributes()), self::LABEL_KOLOM),
            $pengguna,
        );
    }

    /** @param  array<string, mixed>  $sebelum  atribut mentah sebelum diubah */
    public function catatDiubah(User $pengguna, array $sebelum, User $oleh): void
    {
        $rincian = LogPerubahanService::rincian(
            $this->teksTampilan($sebelum),
            $this->teksTampilan($pengguna->getAttributes()),
            self::LABEL_KOLOM,
        );

        if ($rincian === []) {
            return;
        }

        $this->log->catat($oleh, LogPerubahan::KATEGORI_PENGGUNA, 'pengguna.ubah', "Data pengguna diubah: {$pengguna->nama}", $rincian, $pengguna);
    }

    public function catatDihapus(User $pengguna, User $oleh): void
    {
        $this->log->catat(
            $oleh,
            LogPerubahan::KATEGORI_PENGGUNA,
            'pengguna.hapus',
            "Pengguna dihapus: {$pengguna->nama} ({$pengguna->email})",
            [],
            $pengguna,
        );
    }

    /** Password diganti Admin Utama; pengguna itu keluar dari semua perangkat. Password tidak dicatat. */
    public function aturUlangPassword(User $pengguna, string $passwordBaru, User $oleh): void
    {
        DB::transaction(function () use ($pengguna, $passwordBaru, $oleh) {
            $pengguna->aturPassword($passwordBaru);

            $this->log->catat($oleh, LogPerubahan::KATEGORI_PENGGUNA, 'pengguna.password', "Password diatur ulang: {$pengguna->nama}", [], $pengguna);
        });
    }

    /** Nilai kolom sebagai teks tampilan: peran menjadi labelnya, lokasi menjadi nama lokasinya. */
    private function teksTampilan(array $data): array
    {
        $role = $data['role'] ?? null;
        $cabangId = $data['cabang_id'] ?? null;

        return [
            'nama'      => $data['nama'] ?? null,
            'email'     => $data['email'] ?? null,
            'role'      => $role === null ? null : (User::LABEL_ROLE[$role] ?? $role),
            'cabang_id' => $cabangId ? Cabang::query()->whereKey($cabangId)->value('nama') : 'Semua Lokasi',
        ];
    }
}
