<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Barang keluar: pemakaian dapur (dari penjualan) dan barang rusak. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang_keluar', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->date('tanggal');
            $table->enum('periode', ['sehari', 'siang', 'malam'])->default('sehari');
            $table->enum('jenis', ['pemakaian', 'rusak']);
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draf', 'menunggu', 'disetujui', 'revisi', 'batal'])->default('menunggu');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('barang_keluar_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barang_keluar_id')->constrained('barang_keluar')->cascadeOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->enum('sebab', ['dipakai', 'rusak', 'kedaluwarsa', 'tumpah', 'retur']);
            $table->string('penjelasan', 160)->nullable();
            $table->string('foto_path')->nullable();
            $table->foreignId('diketahui_oleh')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang_keluar_detail');
        Schema::dropIfExists('barang_keluar');
    }
};
