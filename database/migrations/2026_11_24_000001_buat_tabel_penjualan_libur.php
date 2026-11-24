<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Hari libur cabang: tanggal tanpa penjualan yang ditandai libur, jadi tidak diingatkan "belum dicatat". */
    public function up(): void
    {
        Schema::create('penjualan_libur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->dateTime('tanggal');
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->datetimes();

            $table->unique(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penjualan_libur');
    }
};
