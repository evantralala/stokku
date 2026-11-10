<?php

namespace App\Console\Commands;

use App\Models\Pengaturan;
use App\Services\KontrolSistemService;
use App\Services\PermintaanService;
use App\Support\DaftarKontrol;
use App\Support\VersiData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Membuat permintaan otomatis untuk bahan cabang yang stoknya di bawah minimum.
 * Dijadwalkan berkala (routes/console.php); hanya berjalan sekali sehari setelah
 * jam yang diatur di Pengaturan (jam_permintaan_otomatis).
 */
class BuatPermintaanOtomatis extends Command
{
    protected $signature = 'permintaan:buat-otomatis {--paksa : Jalankan sekarang tanpa melihat jam dan tanpa batas sekali sehari}';

    protected $description = 'Buat permintaan otomatis untuk bahan cabang yang stoknya di bawah minimum';

    private const JAM_BAWAAN = '06:00';

    public function handle(PermintaanService $layanan, KontrolSistemService $kontrol): int
    {
        if (! $kontrol->modulNyala(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS)) {
            $this->line($kontrol->teksModulMati(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS).' Dilewati.');

            return self::SUCCESS;
        }

        $sekarang = now();
        $kunciHariIni = 'permintaan-otomatis:'.$sekarang->toDateString();
        $paksa = (bool) $this->option('paksa');

        if (! $paksa) {
            $jam = (string) Pengaturan::ambil('jam_permintaan_otomatis', self::JAM_BAWAAN);

            if ($sekarang->format('H:i') < $jam) {
                $this->line("Belum waktunya. Permintaan otomatis berjalan setelah pukul {$jam}.");

                return self::SUCCESS;
            }

            if (Cache::has($kunciHariIni)) {
                $this->line('Permintaan otomatis hari ini sudah dibuat.');

                return self::SUCCESS;
            }
        }

        $hasil = $layanan->buatOtomatis();

        Cache::put($kunciHariIni, true, $sekarang->copy()->endOfDay());

        if ($hasil === []) {
            $this->info('Tidak ada bahan cabang yang di bawah minimum.');

            return self::SUCCESS;
        }

        VersiData::naikkan();

        foreach ($hasil as $cabang => $jumlah) {
            $this->info("{$cabang}: {$jumlah} bahan diminta.");
        }

        return self::SUCCESS;
    }
}
