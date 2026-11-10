<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Buku besar stok (stok_mutasi, tidak pernah diubah) dan saldo per lokasi per bahan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stok_mutasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('jumlah', 14, 3);
            $table->enum('jenis', [
                'masuk_supplier',
                'kirim_keluar',
                'kirim_masuk',
                'pakai_dapur',
                'rusak',
                'koreksi_opname',
                'stok_awal',
                'tambah_langsung',
                'penyesuaian',
            ]);
            $table->enum('status', ['menunggu', 'sah', 'batal'])->default('menunggu');
            $table->string('dokumen_tipe', 30);
            $table->unsignedBigInteger('dokumen_id');
            $table->unsignedBigInteger('dokumen_detail_id')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('dibuat_pada')->useCurrent();

            $table->index(['cabang_id', 'bahan_baku_id', 'status']);
            $table->index(['dokumen_tipe', 'dokumen_id']);
        });

        Schema::create('stok_saldo', function (Blueprint $table) {
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('saldo', 14, 3)->default(0);
            $table->timestamp('diperbarui_pada')->useCurrent();

            $table->primary(['cabang_id', 'bahan_baku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stok_saldo');
        Schema::dropIfExists('stok_mutasi');
    }
};
