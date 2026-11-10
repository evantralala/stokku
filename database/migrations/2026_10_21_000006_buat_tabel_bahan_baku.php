<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bahan baku dan stok minimumnya per lokasi. Stok disimpan dalam satuan dasar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahan_baku', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->string('foto', 255)->nullable();
            $table->foreignId('kategori_id')->constrained('kategori')->restrictOnDelete();
            $table->foreignId('satuan_dasar_id')->constrained('satuan')->restrictOnDelete();
            $table->foreignId('satuan_beli_id')->constrained('satuan')->restrictOnDelete();
            $table->decimal('isi_per_satuan_beli', 12, 3);
            $table->decimal('toleransi_persen', 5, 2)->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('bahan_baku_minimum', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->decimal('minimum', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['bahan_baku_id', 'cabang_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan_baku_minimum');
        Schema::dropIfExists('bahan_baku');
    }
};
