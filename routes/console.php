<?php

use App\Support\DaftarLaporanEmail;
use Illuminate\Support\Facades\Schedule;

// Butuh cron hosting: * * * * * php artisan schedule:run. Tanpa cron, Permintaan Otomatis, Tolak Otomatis, dan email laporan harian tidak berjalan.
// Jam sebenarnya diatur Admin Utama di Pengaturan; perintah ini sendiri memastikan hanya berjalan sekali sehari.
Schedule::command('permintaan:buat-otomatis')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('persetujuan:tolak-otomatis')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('laporan:kirim-harian')->dailyAt(DaftarLaporanEmail::JAM_KIRIM)->withoutOverlapping();

Schedule::command('laporan:kirim-harian --susulan')->dailyAt(DaftarLaporanEmail::JAM_SUSULAN)->withoutOverlapping();
