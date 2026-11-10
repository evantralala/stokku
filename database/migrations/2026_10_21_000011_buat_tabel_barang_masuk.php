<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Barang masuk ke gudang pusat (menunggu persetujuan Admin Utama). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang_masuk', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->date('tanggal');
            $table->text('catatan')->nullable();
            $table->foreignId('dicatat_oleh')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draf', 'menunggu', 'disetujui', 'revisi', 'batal'])->default('menunggu');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('barang_masuk_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_masuk_id')->constrained('barang_masuk')->cascadeOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->string('keterangan', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang_masuk_detail');
        Schema::dropIfExists('barang_masuk');
    }
};
