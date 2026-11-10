<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Angka aturan yang bisa diubah Admin Utama. Isinya dari PengaturanSeeder. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 50)->unique();
            $table->string('label', 100)->nullable();
            $table->string('nilai');
            $table->string('tipe', 20)->default('teks');
            $table->string('satuan', 20)->nullable();
            $table->decimal('nilai_min', 12, 2)->nullable();
            $table->decimal('nilai_maks', 12, 2)->nullable();
            $table->string('keterangan', 160)->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
    }
};
