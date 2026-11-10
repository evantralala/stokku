<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Pengaturan jam ganti hari penjualan (hari operasional): sebelum jam ini tanggal bawaan penjualan masih kemarin. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('pengaturan')->where('kunci', 'jam_ganti_hari_penjualan')->exists()) {
            return;
        }

        DB::table('pengaturan')->insert([
            'kunci'      => 'jam_ganti_hari_penjualan',
            'label'      => 'Jam Ganti Hari Penjualan',
            'nilai'      => '06:00',
            'tipe'       => 'jam',
            'keterangan' => 'Sebelum jam ini tanggal penjualan masih dianggap kemarin, karena penjualan dicatat setelah closing tengah malam.',
            'urutan'     => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('pengaturan')->where('kunci', 'jam_ganti_hari_penjualan')->delete();
    }
};
