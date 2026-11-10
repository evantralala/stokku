<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Foto profil pengguna (path di disk public), diatur sendiri lewat menu Akun. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('foto', 255)->nullable()->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('foto');
        });
    }
};
