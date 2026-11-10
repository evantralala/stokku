<?php

namespace Database\Seeders;

use App\Services\AdminUtamaAwalService;
use Illuminate\Database\Seeder;

/**
 * Memastikan selalu ada minimal satu akun Admin Utama aktif.
 * Data akun diambil dari ADMIN_UTAMA_NAMA, ADMIN_UTAMA_EMAIL, dan ADMIN_UTAMA_PASSWORD di .env
 * (lihat App\Services\AdminUtamaAwalService untuk nilai pengganti bila kosong).
 */
class AdminUtamaAwalSeeder extends Seeder
{
    public function run(AdminUtamaAwalService $admin): void
    {
        $hasil = $admin->pastikanAda();

        if ($hasil === null) {
            $this->command?->info('Akun Admin Utama aktif sudah ada, tidak ada yang diubah.');

            return;
        }

        $this->command?->info(($hasil['dipulihkan'] ? 'Akun Admin Utama dipulihkan: ' : 'Akun Admin Utama dibuat: ').$hasil['email']);

        if ($hasil['password_dibuat'] !== null) {
            $this->command?->warn('ADMIN_UTAMA_PASSWORD di .env kosong, jadi password dibuat otomatis: '.$hasil['password_dibuat']);
            $this->command?->warn('Catat sekarang dan segera ganti lewat menu Akun saya setelah masuk.');
        }
    }
}
