<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sakelar sistem: mode perbaikan, modul, hak akses, dan detak penjadwal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kontrol_sistem', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 60)->unique();
            $table->text('nilai')->nullable();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kontrol_sistem');
    }
};
