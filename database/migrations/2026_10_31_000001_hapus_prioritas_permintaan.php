<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prioritas permintaan (biasa/mendesak) dihapus. Urutan antrean kini mengikuti batas waktu keputusan dan waktu pengajuan.
 * Database baru sudah dibuat tanpa kolom ini; migration ini untuk database lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('permintaan', 'prioritas')) {
            Schema::table('permintaan', fn (Blueprint $table) => $table->dropColumn('prioritas'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('permintaan', 'prioritas')) {
            Schema::table('permintaan', fn (Blueprint $table) => $table->enum('prioritas', ['biasa', 'mendesak'])->default('biasa'));
        }
    }
};
