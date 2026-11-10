<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Menu dan resepnya. Versi resep dibedakan dengan tanggal berlaku_sejak. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->string('foto', 255)->nullable();
            $table->foreignId('kategori_menu_id')->constrained('kategori_menu')->restrictOnDelete();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('resep', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menu')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('jumlah', 14, 3);
            $table->string('catatan', 160)->nullable();
            $table->date('berlaku_sejak');
            $table->timestamps();

            $table->index(['menu_id', 'berlaku_sejak']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resep');
        Schema::dropIfExists('menu');
    }
};
