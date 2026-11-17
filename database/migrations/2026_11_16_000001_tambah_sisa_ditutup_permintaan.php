<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Penanda Admin Utama menutup sisa permintaan yang belum terkirim penuh. */
    public function up(): void
    {
        Schema::table('permintaan', function (Blueprint $table) {
            $table->dateTime('sisa_ditutup_pada')->nullable()->after('status');
            $table->foreignId('sisa_ditutup_oleh')->nullable()->after('sisa_ditutup_pada')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('permintaan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sisa_ditutup_oleh');
            $table->dropColumn('sisa_ditutup_pada');
        });
    }
};
