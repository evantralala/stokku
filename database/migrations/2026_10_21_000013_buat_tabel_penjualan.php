<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penjualan harian cabang (porsi per menu) dengan takaran resep yang berlaku saat itu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penjualan_harian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->date('tanggal');
            $table->foreignId('barang_keluar_id')->nullable()->constrained('barang_keluar')->restrictOnDelete();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cabang_id', 'tanggal']);
        });

        Schema::create('penjualan_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penjualan_harian_id')->constrained('penjualan_harian')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menu')->restrictOnDelete();
            $table->unsignedInteger('porsi');
            $table->json('takaran_resep_json');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['penjualan_harian_id', 'menu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penjualan_detail');
        Schema::dropIfExists('penjualan_harian');
    }
};
