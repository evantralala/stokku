<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keputusan Admin Utama atas dokumen (setujui, tolak, minta perbaikan). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persetujuan', function (Blueprint $table) {
            $table->id();
            $table->enum('dokumen_tipe', ['permintaan', 'barang_masuk', 'barang_keluar', 'kiriman', 'opname']);
            $table->unsignedBigInteger('dokumen_id');
            $table->unsignedBigInteger('dokumen_detail_id')->nullable();
            $table->enum('keputusan', ['disetujui', 'ditolak', 'otomatis_ditolak', 'revisi']);
            $table->decimal('qty_disetujui', 14, 3)->nullable();
            $table->string('catatan', 160)->nullable();
            $table->foreignId('oleh_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('pada')->useCurrent();

            $table->index(['dokumen_tipe', 'dokumen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan');
    }
};
