<?php

namespace Tests;

use App\Models\User;
use App\Services\KontrolSistemService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test tidak bergantung pada hasil npm run build.
        $this->withoutVite();

        // Jam tetap di siang hari supaya hasil tes tidak bergantung jam dijalankan (mis. hari operasional penjualan
        // sebelum jam 06.00 masih kemarin). Tes yang butuh jam lain memanggil travelTo sendiri.
        $this->travelTo(now()->setTime(10, 0));
    }

    /** Ubah satu hak akses peran tanpa mengubah hak akses lainnya. */
    protected function aturHakAkses(string $role, string $menu, string $aksi, bool $boleh): void
    {
        $kontrol = app(KontrolSistemService::class);
        $isian = collect($kontrol->tabelAkses($role))
            ->map(fn (array $m) => collect($m['aksi'])->map(fn (array $a) => $a['boleh'])->all())
            ->all();
        $isian[$menu][$aksi] = $boleh;

        $kontrol->aturAkses($role, $isian, User::query()->where('role', User::ROLE_ADMIN_UTAMA)->first() ?? User::factory()->adminUtama()->create());
    }

    /** Nyalakan "Tambah Stok" untuk Admin Pusat (bawaannya mati). */
    protected function izinkanUbahStokPusat(): void
    {
        $this->aturHakAkses(User::ROLE_ADMIN_PUSAT, 'ubah-stok', 'catat', true);
    }
}
