<?php

namespace App\Services;

use App\Models\KontrolSistem;
use App\Models\LogPerubahan;
use App\Models\User;
use App\Support\DaftarKontrol;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sakelar sistem yang diatur Admin Utama di halaman Pengaturan.
 *
 * - Modul: fitur yang bisa dimatikan (DaftarKontrol::MODUL). Bawaan menyala.
 * - Hak akses per peran per aksi (DaftarKontrol::MENU). Bawaan boleh (kecuali aksi di 'mati'); menu yang dikunci selalu boleh.
 *
 * Semua nilai dibaca sekali per request.
 */
class KontrolSistemService
{
    private const AWALAN_MODUL = 'modul.';
    private const AWALAN_AKSES = 'akses.';

    public function __construct(private LogPerubahanService $log) {}

    /* ------------------------------------------------------------------
     | Modul
     * ------------------------------------------------------------------ */

    /** Modul menyala bila tidak dimatikan Admin Utama. */
    public function modulNyala(string $modul): bool
    {
        return $this->nilai(self::AWALAN_MODUL.$modul) !== '0';
    }

    /** Pesan untuk halaman yang modulnya sedang dimatikan. */
    public function teksModulMati(string $modul): string
    {
        $label = DaftarKontrol::MODUL[$modul]['label'] ?? $modul;

        return "Modul {$label} sedang dimatikan.";
    }

    /** @return array<string, array{label: string, keterangan: string, efek_mati: string, nyala: bool}> */
    public function daftarModul(): array
    {
        $hasil = [];

        foreach (DaftarKontrol::MODUL as $modul => $definisi) {
            $hasil[$modul] = $definisi + ['nyala' => $this->modulNyala($modul)];
        }

        return $hasil;
    }

    /** @throws DomainException */
    public function aturModul(string $modul, bool $nyala, User $oleh): void
    {
        $definisi = DaftarKontrol::MODUL[$modul] ?? throw new DomainException('Modul tidak dikenal. Muat ulang halaman lalu coba lagi.');

        DB::transaction(function () use ($modul, $nyala, $oleh, $definisi) {
            if ($this->modulNyala($modul) === $nyala) {
                return;
            }

            if (! $nyala) {
                foreach (DaftarKontrol::SYARAT_MODUL as $akses => $syarat) {
                    [$role, $menu, $aksi] = explode('.', $akses);

                    if ($syarat === $modul && ! $this->bolehPeran($role, $menu, $aksi)) {
                        throw new DomainException(sprintf(
                            'Modul %s tidak bisa dimatikan selama %s %s untuk %s dimatikan. Nyalakan aksesnya dulu.',
                            $definisi['label'],
                            mb_strtolower(DaftarKontrol::LABEL_AKSI[$aksi]),
                            mb_strtolower(DaftarKontrol::menuPeran($role)[$menu]['label']),
                            User::LABEL_ROLE[$role],
                        ));
                    }
                }
            }

            $this->simpan(self::AWALAN_MODUL.$modul, $nyala ? '1' : '0', $oleh);

            $this->log->catat(
                $oleh,
                LogPerubahan::KATEGORI_SISTEM,
                $nyala ? 'modul.nyala' : 'modul.mati',
                'Modul '.$definisi['label'].($nyala ? ' dinyalakan' : ' dimatikan'),
            );
        });
    }

    /* ------------------------------------------------------------------
     | Hak akses
     * ------------------------------------------------------------------ */

    /** Boleh melakukan "menu.aksi" (mis. "opname.catat")? Admin Utama selalu boleh. */
    public function boleh(?User $user, string $menuAksi): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdminUtama()) {
            return true;
        }

        [$menu, $aksi] = array_pad(explode('.', $menuAksi, 2), 2, DaftarKontrol::AKSI_LIHAT);

        return $this->bolehPeran($user->role, $menu, $aksi);
    }

    /**
     * Boleh membuka route ini dengan metode HTTP ini? Route yang tidak diatur selalu boleh, kecuali route Admin Utama:
     * peran lain hanya boleh membuka menu Admin Utama yang dibagikan ke perannya dan sedang dinyalakan.
     */
    public function bolehRute(User $user, ?string $namaRute, string $metode = 'GET'): bool
    {
        if ($user->isAdminUtama()) {
            return true;
        }

        $menuAksi = DaftarKontrol::aksiRute($user->role, $namaRute, $metode);

        if ($menuAksi === null) {
            return ! str_starts_with((string) $namaRute, DaftarKontrol::AWALAN_UTAMA.'.');
        }

        return $this->bolehPeran($user->role, ...$menuAksi);
    }

    /** bolehRute untuk request yang sedang berjalan (impor/ekspor Excel dan Riwayat dicocokkan ke menu pemiliknya). */
    public function bolehRequest(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $route = $request->route();
        $master = $route?->parameter('master');
        $jenis = $request->query('jenis');

        return $this->bolehRute(
            $user,
            DaftarKontrol::namaRuteEfektif($route?->getName(), is_string($master) ? $master : null, is_string($jenis) ? $jenis : null),
            $request->method(),
        );
    }

    public function bolehPeran(string $role, string $menu, string $aksi): bool
    {
        $definisi = DaftarKontrol::menuPeran($role)[$menu] ?? null;

        if ($definisi === null || ! in_array($aksi, $definisi['aksi'], true) || in_array($aksi, $definisi['kunci'], true)) {
            return true;
        }

        if ($aksi === DaftarKontrol::AKSI_CATAT
            && in_array(DaftarKontrol::AKSI_LIHAT, $definisi['aksi'], true)
            && ! $this->bolehPeran($role, $menu, DaftarKontrol::AKSI_LIHAT)) {
            return false;
        }

        return ($this->nilai($this->kunciAkses($role, $menu, $aksi)) ?? $this->bawaanAkses($definisi, $aksi)) !== '0';
    }

    /** Nilai hak akses sebelum pernah diatur: boleh, kecuali aksi yang tercantum di 'mati' menu itu. */
    private function bawaanAkses(array $definisi, string $aksi): string
    {
        return in_array($aksi, $definisi['mati'] ?? [], true) ? '0' : '1';
    }

    /**
     * Tabel hak akses satu peran untuk ditampilkan.
     *
     * @return array<string, array{label: string, grup: ?string, aksi: array<string, array{ada: bool, kunci: bool, boleh: bool}>}>
     */
    public function tabelAkses(string $role): array
    {
        $hasil = [];

        foreach (DaftarKontrol::menuPeran($role) as $menu => $definisi) {
            $aksi = [];

            foreach (array_keys(DaftarKontrol::LABEL_AKSI) as $a) {
                $aksi[$a] = [
                    'ada'   => in_array($a, $definisi['aksi'], true),
                    'kunci' => in_array($a, $definisi['kunci'], true),
                    'boleh' => $this->bolehPeran($role, $menu, $a),
                ];
            }

            $hasil[$menu] = ['label' => $definisi['label'], 'grup' => $definisi['grup'] ?? null, 'aksi' => $aksi];
        }

        return $hasil;
    }

    /**
     * Simpan hak akses satu peran. Aksi yang dikunci diabaikan; catat ikut mati bila lihat mati.
     *
     * @param  array<string, array<string, mixed>>  $isian  [menu => [aksi => bool]]
     * @return int jumlah aksi yang berubah
     *
     * @throws DomainException
     */
    public function aturAkses(string $role, array $isian, User $oleh): int
    {
        $menuPeran = DaftarKontrol::menuPeran($role) ?: throw new DomainException('Peran tidak dikenal. Muat ulang halaman lalu coba lagi.');

        return DB::transaction(function () use ($role, $isian, $oleh, $menuPeran) {
            $baru = [];

            foreach ($menuPeran as $menu => $definisi) {
                $bisaLihat = ! in_array(DaftarKontrol::AKSI_LIHAT, DaftarKontrol::bisaDiatur($definisi), true)
                    || (bool) ($isian[$menu][DaftarKontrol::AKSI_LIHAT] ?? false);

                foreach (DaftarKontrol::bisaDiatur($definisi) as $aksi) {
                    $boleh = (bool) ($isian[$menu][$aksi] ?? false);
                    $baru[$menu][$aksi] = $aksi === DaftarKontrol::AKSI_CATAT ? $boleh && $bisaLihat : $boleh;
                }
            }

            foreach (DaftarKontrol::SYARAT_MODUL as $akses => $modul) {
                [$r, $menu, $aksi] = explode('.', $akses);

                if ($r === $role && ($baru[$menu][$aksi] ?? true) === false && ! $this->modulNyala($modul)) {
                    throw new DomainException(sprintf(
                        '%s %s tidak bisa dimatikan selama modul %s mati. Nyalakan modulnya dulu supaya kiriman tetap punya permintaan.',
                        DaftarKontrol::LABEL_AKSI[$aksi],
                        mb_strtolower($menuPeran[$menu]['label']),
                        DaftarKontrol::MODUL[$modul]['label'],
                    ));
                }
            }

            $lama = [];

            foreach ($baru as $menu => $aksiBaru) {
                foreach (array_keys($aksiBaru) as $aksi) {
                    $lama[$menu][$aksi] = $this->bolehPeran($role, $menu, $aksi);
                }
            }

            $rincian = [];

            foreach ($baru as $menu => $aksiBaru) {
                foreach ($aksiBaru as $aksi => $boleh) {
                    $kunci = $this->kunciAkses($role, $menu, $aksi);
                    $nilaiBaru = $boleh ? '1' : '0';

                    if (($this->nilai($kunci) ?? $this->bawaanAkses($menuPeran[$menu], $aksi)) !== $nilaiBaru) {
                        $this->simpan($kunci, $nilaiBaru, $oleh);
                    }

                    if ($lama[$menu][$aksi] !== $boleh) {
                        $rincian[] = [
                            'label' => $menuPeran[$menu]['label'].' · '.DaftarKontrol::LABEL_AKSI[$aksi],
                            'lama'  => $lama[$menu][$aksi] ? 'Boleh' : 'Tidak',
                            'baru'  => $boleh ? 'Boleh' : 'Tidak',
                        ];
                    }
                }
            }

            if ($rincian !== []) {
                $this->log->catat(
                    $oleh,
                    LogPerubahan::KATEGORI_SISTEM,
                    'akses.ubah',
                    'Hak akses '.User::LABEL_ROLE[$role].' diubah',
                    $rincian,
                );
            }

            return count($rincian);
        });
    }

    /* ------------------------------------------------------------------
     | Penyimpanan
     * ------------------------------------------------------------------ */

    private function kunciAkses(string $role, string $menu, string $aksi): string
    {
        return self::AWALAN_AKSES."{$role}.{$menu}.{$aksi}";
    }

    private function nilai(string $kunci): ?string
    {
        return $this->semua()[$kunci] ?? null;
    }

    /** Semua baris kontrol, dibaca sekali per request. */
    private function semua(): array
    {
        $request = request();
        $simpanan = $request->attributes->get(self::class);

        if (! is_array($simpanan)) {
            $simpanan = KontrolSistem::query()->pluck('nilai', 'kunci')->all();
            $request->attributes->set(self::class, $simpanan);
        }

        return $simpanan;
    }

    private function simpan(string $kunci, ?string $nilai, User $oleh): void
    {
        KontrolSistem::query()->updateOrCreate(
            ['kunci' => $kunci],
            ['nilai' => $nilai, 'diubah_oleh' => $oleh->id],
        );

        request()->attributes->remove(self::class);
    }
}
