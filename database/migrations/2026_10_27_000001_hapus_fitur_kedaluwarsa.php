<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur tanggal kedaluwarsa dihapus.
 * Database baru sudah dibuat tanpa kolom ini (migration buat_tabel_* sudah disesuaikan); migration ini membersihkan
 * database lama: tabel batch, kolom tanggal kedaluwarsa, pengaturan per bahan/kategori, dan modulnya di kontrol sistem.
 * Pilihan "kedaluwarsa" di sebab barang rusak dan kondisi terima kiriman dipindah ke "rusak".
 */
return new class extends Migration
{
    private const KOLOM = [
        'kategori'            => ['peringatan_kedaluwarsa_hari'],
        'bahan_baku'          => ['lacak_kedaluwarsa', 'umur_simpan_hari'],
        'barang_masuk_detail' => ['tanggal_kedaluwarsa'],
        'tambah_stok'         => ['tanggal_kedaluwarsa'],
        'penyesuaian_stok'    => ['tanggal_kedaluwarsa'],
        'stok_mutasi'         => ['tanggal_kedaluwarsa'],
    ];

    public function up(): void
    {
        Schema::dropIfExists('stok_mutasi_batch');
        Schema::dropIfExists('stok_batch');

        if (Schema::hasColumn('stok_mutasi', 'asal_mutasi_id')) {
            Schema::table('stok_mutasi', function (Blueprint $table) {
                $table->dropConstrainedForeignId('asal_mutasi_id');
            });
        }

        foreach (self::KOLOM as $tabel => $kolom) {
            $ada = array_values(array_filter($kolom, fn (string $k) => Schema::hasColumn($tabel, $k)));

            if ($ada !== []) {
                Schema::table($tabel, fn (Blueprint $table) => $table->dropColumn($ada));
            }
        }

        DB::table('barang_keluar_detail')->where('sebab', 'kedaluwarsa')->update(['sebab' => 'rusak']);
        DB::table('kiriman_detail')->where('kondisi', 'kedaluwarsa')->update(['kondisi' => 'rusak']);

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE barang_keluar_detail MODIFY sebab ENUM('dipakai', 'rusak', 'tumpah', 'retur') NOT NULL");
            DB::statement("ALTER TABLE kiriman_detail MODIFY kondisi ENUM('baik', 'rusak', 'tidak_ada') NULL");
        }

        DB::table('kontrol_sistem')->where('kunci', 'modul.kedaluwarsa')->delete();
    }

    public function down(): void
    {
        // Data kedaluwarsa yang sudah dihapus tidak bisa dikembalikan.
    }
};
