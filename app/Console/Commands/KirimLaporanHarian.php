<?php

namespace App\Console\Commands;

use App\Services\LaporanHarianService;
use Illuminate\Console\Command;

/**
 * Kirim email laporan harian ke semua akun yang menyalakannya (dijadwalkan pukul 23.59 di routes/console.php).
 * --susulan dijalankan lewat tengah malam untuk akun yang laporan kemarinnya belum terkirim (mis. cron hosting sempat terlambat).
 */
class KirimLaporanHarian extends Command
{
    protected $signature = 'laporan:kirim-harian
        {--ulang : Kirim lagi walau hari ini sudah terkirim}
        {--susulan : Kirim laporan kemarin yang belum terkirim}';

    protected $description = 'Kirim email laporan harian sesuai pengaturan di menu Akun';

    public function handle(LaporanHarianService $layanan): int
    {
        $hasil = $layanan->kirimSemua($this->option('susulan') ? now()->subDay()->setTime(23, 59) : null, (bool) $this->option('ulang'));

        $this->info("Terkirim: {$hasil['terkirim']} akun. Gagal: {$hasil['gagal']} akun.");

        return $hasil['gagal'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
