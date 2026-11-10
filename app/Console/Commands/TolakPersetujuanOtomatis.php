<?php

namespace App\Console\Commands;

use App\Services\KontrolSistemService;
use App\Services\PersetujuanService;
use App\Support\DaftarKontrol;
use App\Support\VersiData;
use Illuminate\Console\Command;

/** Tolak otomatis barang permintaan yang belum diputuskan Admin Utama sampai batas waktunya. */
class TolakPersetujuanOtomatis extends Command
{
    protected $signature = 'persetujuan:tolak-otomatis';

    protected $description = 'Tolak otomatis barang permintaan yang melewati batas waktu persetujuan';

    public function handle(PersetujuanService $layanan, KontrolSistemService $kontrol): int
    {
        if (! $kontrol->modulNyala(DaftarKontrol::MODUL_TOLAK_OTOMATIS)) {
            $this->line($kontrol->teksModulMati(DaftarKontrol::MODUL_TOLAK_OTOMATIS).' Dilewati.');

            return self::SUCCESS;
        }

        $jumlah = $layanan->tolakOtomatis();

        if ($jumlah > 0) {
            VersiData::naikkan();
        }

        $this->info($jumlah > 0
            ? "{$jumlah} barang permintaan ditolak otomatis karena melewati batas waktu."
            : 'Tidak ada permintaan yang melewati batas waktu.');

        return self::SUCCESS;
    }
}
