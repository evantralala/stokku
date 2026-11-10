<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notifikasi per pengguna. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifikasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('judul', 100);
            $table->string('keterangan')->nullable();
            $table->string('tautan')->nullable();
            $table->timestamp('dibaca_pada')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'dibaca_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifikasi');
    }
};
