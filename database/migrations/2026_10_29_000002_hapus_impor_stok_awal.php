<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Impor data awal dihapus; stok awal diisi lewat Perbarui stok lewat Excel di halaman Stok.
 * Database baru tidak lagi membuat tabel impor_stok; migration ini menghapusnya dari database lama.
 * Kartu stok (stok_mutasi) berjenis stok awal tetap tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('impor_stok');
    }

    public function down(): void
    {
        if (Schema::hasTable('impor_stok')) {
            return;
        }

        Schema::create('impor_stok', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->string('nama_file', 150)->nullable();
            $table->unsignedInteger('jumlah_baris');
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
