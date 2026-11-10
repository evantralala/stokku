<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standar stok per lokasi dihapus. Jumlah permintaan otomatis dan batas wajar permintaan kini memakai
 * stok minimum ditambah persen cadangan (Pengaturan "Cadangan isi stok").
 * Database baru sudah dibuat tanpa kolom ini; migration ini untuk database lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cabang', 'standar_stok_hari')) {
            Schema::table('cabang', fn (Blueprint $table) => $table->dropColumn('standar_stok_hari'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cabang', 'standar_stok_hari')) {
            Schema::table('cabang', fn (Blueprint $table) => $table->unsignedTinyInteger('standar_stok_hari')->default(3));
        }
    }
};
