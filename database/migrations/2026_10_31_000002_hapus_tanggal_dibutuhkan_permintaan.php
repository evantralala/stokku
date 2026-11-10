<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isian "Dibutuhkan Tanggal" di permintaan dihapus: tidak dipakai perhitungan apa pun, batas waktu memakai batas keputusan.
 * Database baru sudah dibuat tanpa kolom ini; migration ini untuk database lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('permintaan', 'butuh_tanggal')) {
            Schema::table('permintaan', fn (Blueprint $table) => $table->dropColumn('butuh_tanggal'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('permintaan', 'butuh_tanggal')) {
            Schema::table('permintaan', fn (Blueprint $table) => $table->date('butuh_tanggal')->nullable()->after('batas_keputusan'));
        }
    }
};
