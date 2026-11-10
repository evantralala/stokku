<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nama lain bahan (dipisah koma, mis. "telor, egg"): dipakai pencarian bahan, tempel daftar, dan impor Excel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bahan_baku', function (Blueprint $table) {
            $table->string('nama_lain', 500)->nullable()->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('bahan_baku', function (Blueprint $table) {
            $table->dropColumn('nama_lain');
        });
    }
};
