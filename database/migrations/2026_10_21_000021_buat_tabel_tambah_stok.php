<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tambah stok langsung tanpa persetujuan (tetap tercatat di riwayat). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tambah_stok', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('jumlah', 14, 3);
            $table->string('keterangan', 160)->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cabang_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tambah_stok');
    }
};
