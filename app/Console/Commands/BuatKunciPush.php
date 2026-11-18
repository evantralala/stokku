<?php

namespace App\Console\Commands;

use App\Support\KriptoPush;
use Illuminate\Console\Command;

/**
 * Buat kunci notifikasi HP (VAPID) dan tulis ke .env. Cukup sekali per server: kunci baru membuat semua perangkat
 * harus mengaktifkan notifikasi lagi, jadi kunci yang sudah ada tidak diganti kecuali dengan --ganti.
 */
class BuatKunciPush extends Command
{
    protected $signature = 'push:buat-kunci {--ganti : Ganti kunci yang sudah ada}';

    protected $description = 'Buat kunci notifikasi HP (VAPID) dan simpan di .env';

    public function handle(): int
    {
        $berkas = $this->laravel->environmentFilePath();
        $isi = is_file($berkas) ? (string) file_get_contents($berkas) : '';
        $ada = preg_match('/^VAPID_PUBLIC_KEY=\S+/m', $isi) === 1 && preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $isi) === 1;

        if ($ada && ! $this->option('ganti')) {
            $this->info('Kunci notifikasi HP sudah ada di .env, tidak diubah.');

            return self::SUCCESS;
        }

        $kunci = KriptoPush::buatKunci();
        foreach (['VAPID_PUBLIC_KEY' => $kunci['publik'], 'VAPID_PRIVATE_KEY' => $kunci['privat']] as $nama => $nilai) {
            $baris = $nama.'='.$nilai;
            $isi = preg_match("/^{$nama}=.*$/m", $isi)
                ? preg_replace("/^{$nama}=.*$/m", $baris, $isi)
                : rtrim($isi, "\n")."\n".$baris."\n";
        }
        file_put_contents($berkas, $isi);

        $this->info('Kunci notifikasi HP disimpan di .env. Jalankan php artisan config:clear (atau optimize di server).');

        return self::SUCCESS;
    }
}
