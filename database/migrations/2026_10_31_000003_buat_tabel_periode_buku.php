<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periode buku yang sudah ditutup: rentang waktu, berkas arsip (Excel + cadangan database), dan ringkasan isinya.
 * mulai/selesai memakai DATETIME: kolom TIMESTAMP wajib isi tanpa nilai bawaan ditolak MariaDB XAMPP (mode ketat).
 * dropIfExists: aman diulang bila percobaan sebelumnya gagal di tengah jalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('periode_buku');

        Schema::create('periode_buku', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 60);
            $table->dateTime('mulai')->nullable();
            $table->dateTime('selesai');
            $table->string('berkas_excel', 120)->nullable();
            $table->string('berkas_cadangan', 120)->nullable();
            $table->json('ringkasan')->nullable();
            $table->json('penjualan_terakhir')->nullable();
            $table->foreignId('ditutup_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periode_buku');
    }
};
