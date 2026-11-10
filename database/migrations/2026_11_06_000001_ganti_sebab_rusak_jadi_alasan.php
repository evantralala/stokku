<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Barang rusak cukup mencatat satu alasan per barang (kolom penjelasan). Foto bukti dan saksi dihapus.
 * Sebab lama selain "rusak" (kedaluwarsa, tumpah, retur) ditulis di depan alasan supaya keterangannya tidak hilang,
 * lalu sebab barang rusak disamakan menjadi "rusak". Foto bukti lama ikut dihapus dari penyimpanan.
 */
return new class extends Migration
{
    private const LABEL_SEBAB_LAMA = [
        'kedaluwarsa' => 'Kedaluwarsa',
        'tumpah'      => 'Tumpah / Jatuh',
        'retur'       => 'Dikembalikan ke Supplier',
    ];

    private const MAKS_ALASAN = 160;

    public function up(): void
    {
        DB::table('barang_keluar_detail')
            ->whereIn('barang_keluar_id', DB::table('barang_keluar')->where('jenis', 'rusak')->select('id'))
            ->select(['id', 'sebab', 'penjelasan'])
            ->chunkById(500, function ($baris) {
                foreach ($baris as $b) {
                    $label = self::LABEL_SEBAB_LAMA[$b->sebab] ?? null;
                    $alasan = trim((string) $b->penjelasan);

                    if ($label !== null) {
                        $alasan = $alasan === '' ? $label : $label.': '.$alasan;
                    }

                    DB::table('barang_keluar_detail')->where('id', $b->id)->update([
                        'sebab'      => 'rusak',
                        'penjelasan' => $alasan === '' ? null : mb_substr($alasan, 0, self::MAKS_ALASAN),
                    ]);
                }
            });

        Schema::table('barang_keluar_detail', function (Blueprint $table) {
            $table->dropForeign(['diketahui_oleh']);
        });

        Schema::table('barang_keluar_detail', function (Blueprint $table) {
            $table->dropColumn(['foto_path', 'diketahui_oleh']);
        });

        Storage::disk('public')->deleteDirectory('barang-keluar');
    }

    public function down(): void
    {
        Schema::table('barang_keluar_detail', function (Blueprint $table) {
            $table->string('foto_path')->nullable()->after('penjelasan');
            $table->foreignId('diketahui_oleh')->nullable()->after('foto_path')->constrained('users')->restrictOnDelete();
        });
    }
};
