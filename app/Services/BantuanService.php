<?php

namespace App\Services;

use App\Models\User;
use App\Support\DaftarBantuan;

/**
 * Topik bantuan untuk satu pengguna: hanya halaman yang boleh dibuka, modul yang menyala,
 * dan butir yang sesuai dengan lokasinya (lihat App\Support\DaftarBantuan).
 */
class BantuanService
{
    public function __construct(private KontrolSistemService $kontrol)
    {
    }

    /**
     * @return array<string, array{judul: string, ikon: string, ringkas: string, rute: ?string, langkah: list<string>, tips: list<string>}>
     */
    public function untuk(User $user): array
    {
        $hasil = [];

        foreach ([...(DaftarBantuan::TOPIK[$user->role] ?? []), ...DaftarBantuan::UMUM] as $kunci => $topik) {
            if (! $this->tampil($user, $topik)) {
                continue;
            }

            $hasil[$kunci] = [
                'judul'   => $topik['judul'],
                'ikon'    => $topik['ikon'],
                'ringkas' => $topik['ringkas'],
                'rute'    => $topik['rute'] ?? null,
                'langkah' => $this->butir($user, $topik['langkah'] ?? []),
                'tips'    => $this->butir($user, $topik['tips'] ?? []),
            ];
        }

        return array_filter($hasil, fn (array $t) => $t['langkah'] !== []);
    }

    /**
     * Topik untuk halaman yang sedang dibuka (rute sama atau sub-halamannya; yang paling spesifik), atau null.
     *
     * @return array{kunci: string, judul: string}|null
     */
    public function topikUntukRute(User $user, ?string $namaRute): ?array
    {
        if ($namaRute === null) {
            return null;
        }

        $cocok = null;
        $panjang = 0;

        foreach ($this->untuk($user) as $kunci => $topik) {
            $rute = $topik['rute'];

            if ($rute !== null && ($namaRute === $rute || str_starts_with($namaRute, $rute.'.')) && strlen($rute) > $panjang) {
                $cocok = ['kunci' => $kunci, 'judul' => $topik['judul']];
                $panjang = strlen($rute);
            }
        }

        return $cocok;
    }

    private function tampil(User $user, array $topik): bool
    {
        if (isset($topik['modul']) && ! $this->kontrol->modulNyala($topik['modul'])) {
            return false;
        }

        if (isset($topik['rute']) && ! $this->kontrol->bolehRute($user, $topik['rute'])) {
            return false;
        }

        if (isset($topik['catat'], $topik['rute'])) {
            return $this->kontrol->bolehRute($user, $topik['rute'], 'POST') === $topik['catat'];
        }

        return true;
    }

    /** @return list<string> */
    private function butir(User $user, array $daftar): array
    {
        $hasil = [];

        foreach ($daftar as $b) {
            if (is_string($b)) {
                $hasil[] = $b;

                continue;
            }

            if (isset($b['modul']) && ! $this->kontrol->modulNyala($b['modul'])) {
                continue;
            }

            if (isset($b['catat']) && ! $this->kontrol->bolehRute($user, $b['catat'], 'POST')) {
                continue;
            }

            $hasil[] = $b['teks'];
        }

        return $hasil;
    }
}
