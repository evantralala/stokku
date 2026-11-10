<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengaturan email laporan harian (pukul 23.59) milik tiap akun: nyala/mati, alamat tujuan, dan laporan yang dikirim. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_laporan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('aktif')->default(false);
            $table->json('alamat');
            $table->json('laporan');
            $table->dateTime('terakhir_dikirim')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_laporan');
    }
};
