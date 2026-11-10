<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kiriman gudang pusat ke cabang dan penerimaannya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiriman', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('dari_cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('ke_cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('permintaan_id')->nullable()->constrained('permintaan')->restrictOnDelete();
            $table->foreignId('dikirim_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('dikirim_pada')->nullable();
            $table->dateTime('sampai_pada')->nullable();
            $table->text('catatan_pusat')->nullable();
            $table->enum('status', ['disiapkan', 'dikirim', 'dichecklist', 'selesai'])->default('disiapkan');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kiriman_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiriman_id')->constrained('kiriman')->cascadeOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('qty_kirim', 14, 3);
            $table->decimal('qty_terima', 14, 3)->nullable();
            $table->enum('kondisi', ['baik', 'rusak', 'tidak_ada'])->nullable();
            $table->string('catatan_penerima', 160)->nullable();
            $table->foreignId('dichecklist_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('dichecklist_pada')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiriman_detail');
        Schema::dropIfExists('kiriman');
    }
};
