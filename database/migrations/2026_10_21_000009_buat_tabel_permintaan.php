<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Permintaan bahan dari cabang ke gudang pusat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permintaan', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('diajukan_pada')->nullable();
            $table->dateTime('batas_keputusan')->nullable();
            $table->enum('sumber', ['manual', 'otomatis'])->default('manual');
            $table->enum('status', ['draf', 'menunggu', 'selesai', 'batal'])->default('draf');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cabang_id', 'status', 'batas_keputusan']);
        });

        Schema::create('permintaan_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permintaan_id')->constrained('permintaan')->cascadeOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('qty_diminta', 14, 3);
            $table->decimal('qty_disetujui', 14, 3)->nullable();
            $table->decimal('qty_dikirim', 14, 3)->default(0);
            $table->decimal('sisa_saat_itu', 14, 3)->nullable();
            $table->decimal('minimum_saat_itu', 14, 3)->nullable();
            $table->enum('status', ['menunggu', 'disetujui', 'ditolak', 'otomatis_ditolak'])->default('menunggu');
            $table->string('alasan', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['permintaan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permintaan_detail');
        Schema::dropIfExists('permintaan');
    }
};
