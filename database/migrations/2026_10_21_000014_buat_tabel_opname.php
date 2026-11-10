<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock opname: hitung fisik buta, lalu selisih terhadap stok sistem. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opname', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->date('tanggal');
            $table->foreignId('petugas_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['berjalan', 'dikirim', 'disetujui', 'batal'])->default('berjalan');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('opname_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id')->constrained('opname')->cascadeOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('qty_fisik', 14, 3)->nullable();
            $table->decimal('qty_sistem', 14, 3)->nullable();
            $table->decimal('selisih', 14, 3)->storedAs('qty_fisik - qty_sistem');
            $table->string('catatan', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['opname_id', 'bahan_baku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opname_detail');
        Schema::dropIfExists('opname');
    }
};
