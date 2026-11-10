<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Barang rusak bisa dicatat dengan sebab "Kedaluwarsa" (hanya alasan; tanggal kedaluwarsa tidak dilacak).
 * Database baru sudah memuat pilihan ini dari migration buat_tabel_barang_keluar; migration ini untuk database lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE barang_keluar_detail MODIFY sebab ENUM('dipakai', 'rusak', 'kedaluwarsa', 'tumpah', 'retur') NOT NULL");
        }
    }

    public function down(): void
    {
        DB::table('barang_keluar_detail')->where('sebab', 'kedaluwarsa')->update(['sebab' => 'rusak']);

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE barang_keluar_detail MODIFY sebab ENUM('dipakai', 'rusak', 'tumpah', 'retur') NOT NULL");
        }
    }
};
