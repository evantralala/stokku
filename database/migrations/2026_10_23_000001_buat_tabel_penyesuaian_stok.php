<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Penyesuaian stok langsung (tombol Ubah di halaman Stok) dan jenis mutasi penyesuaian. */
return new class extends Migration
{
    private const JENIS_LAMA = ['masuk_supplier', 'kirim_keluar', 'kirim_masuk', 'pakai_dapur', 'rusak', 'koreksi_opname', 'stok_awal', 'tambah_langsung'];

    public function up(): void
    {
        $this->aturJenisMutasi([...self::JENIS_LAMA, 'penyesuaian']);

        Schema::create('penyesuaian_stok', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('cabang_id')->constrained('cabang')->restrictOnDelete();
            $table->foreignId('bahan_baku_id')->constrained('bahan_baku')->restrictOnDelete();
            $table->decimal('stok_lama', 14, 3);
            $table->decimal('stok_baru', 14, 3);
            $table->decimal('selisih', 14, 3);
            $table->string('keterangan', 160)->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cabang_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penyesuaian_stok');
        $this->aturJenisMutasi(self::JENIS_LAMA);
    }

    /** Daftar enum jenis di MySQL/MariaDB; SQLite memakai daftar dari migration tabel stok. */
    private function aturJenisMutasi(array $jenis): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $daftar = implode(', ', array_map(fn (string $j) => "'{$j}'", $jenis));
        DB::statement("ALTER TABLE `stok_mutasi` MODIFY `jenis` ENUM({$daftar}) NOT NULL");
    }
};
