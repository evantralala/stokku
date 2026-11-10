<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Log perubahan sistem, akun, pengguna, pengaturan, dan data master. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_perubahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('kategori', 30);
            $table->string('aksi', 60);
            $table->string('ringkasan', 255);
            $table->json('rincian')->nullable();
            $table->string('objek_tipe', 60)->nullable();
            $table->unsignedBigInteger('objek_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['kategori', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_perubahan');
    }
};
