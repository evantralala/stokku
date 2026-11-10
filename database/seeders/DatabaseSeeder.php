<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Hanya data yang dibutuhkan supaya sistem bisa dipakai pertama kali.
 * Data lain (lokasi, bahan baku, menu, pengguna) diisi lewat aplikasi.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PengaturanSeeder::class,
            AdminUtamaAwalSeeder::class,
        ]);
    }
}
