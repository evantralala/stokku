<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data master yang dihapus padahal sudah punya riwayat tidak dibuang dari database, melainkan diarsipkan:
 * dihapus_pada diisi, data hilang dari daftar dan pilihan, tetapi laporan dan kartu stok lama tetap menampilkan namanya.
 */
return new class extends Migration
{
    private const TABEL = ['bahan_baku', 'kategori', 'satuan', 'kategori_menu', 'menu', 'cabang', 'users'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dateTime('dihapus_pada')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropIndex(['dihapus_pada']);
                $table->dropColumn('dihapus_pada');
            });
        }
    }
};
