<?php

namespace App\Services;

use App\Models\KontrolSistem;
use App\Models\LogPerubahan;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Identitas usaha yang tampil di aplikasi: nama usaha, nama aplikasi, nama singkat (ikon di HP), kota, dan logo.
 * Diubah Admin Utama di Pengaturan > Identitas Usaha, tanpa mengubah kode. Disimpan di kontrol_sistem (kunci KUNCI)
 * sehingga ikut cadangan database dan tidak terhapus saat tutup buku.
 *
 * Logo yang diunggah diolah dengan GD menjadi beberapa berkas PNG di disk public (folder FOLDER): logo besar,
 * logo kecil (sidebar, ikon tab), dan ikon aplikasi HP (latar putih). Berkas dibaca lewat route identitas.berkas.
 * Bila belum ada logo unggahan, dipakai logo bawaan di public/img.
 */
class IdentitasUsahaService
{
    public const KUNCI = 'identitas_usaha';

    public const DISK = 'public';
    public const FOLDER = 'identitas';

    public const MAKS_LOGO_KB = 4096;

    public const BAWAAN = [
        'nama_usaha'    => 'Dapur Contoh',
        'nama_aplikasi' => 'Stokku',
        'nama_singkat'  => 'Stokku',
        'kota'          => 'Jakarta',
    ];

    public const LABEL = [
        'nama_usaha'    => 'Nama Usaha',
        'nama_aplikasi' => 'Nama Aplikasi',
        'nama_singkat'  => 'Nama Singkat',
        'kota'          => 'Kota',
    ];

    /**
     * Berkas logo: [nama berkas => [lebar maks, tinggi maks, sisi ikon persegi (null = tanpa latar), skala logo di ikon], berkas bawaan].
     * Ikon maskable memakai logo lebih kecil supaya tidak terpotong bentuk ikon HP.
     */
    public const BERKAS = [
        'logo.png'              => [[320, 420, null, 1.0], 'img/logo-stokku.png'],
        'logo-kecil.png'        => [[96, 125, null, 1.0], 'img/logo-stokku-kecil.png'],
        'ikon-192.png'          => [[192, 192, 192, 0.8], 'img/ikon/ikon-192.png'],
        'ikon-512.png'          => [[512, 512, 512, 0.8], 'img/ikon/ikon-512.png'],
        'ikon-maskable-512.png' => [[512, 512, 512, 0.6], 'img/ikon/ikon-maskable-512.png'],
        'apple-touch-icon.png'  => [[180, 180, 180, 0.8], 'img/ikon/apple-touch-icon.png'],
    ];

    private const KUNCI_CACHE = 'identitas-usaha';

    public function __construct(private LogPerubahanService $log) {}

    /** @return array{nama_usaha: string, nama_aplikasi: string, nama_singkat: string, kota: string, logo: ?int} */
    public function data(): array
    {
        try {
            $simpanan = Cache::rememberForever(self::KUNCI_CACHE, function () {
                $nilai = KontrolSistem::query()->where('kunci', self::KUNCI)->value('nilai');

                return is_string($nilai) ? (json_decode($nilai, true) ?: []) : [];
            });
        } catch (Throwable) {
            $simpanan = [];
        }

        $data = ['logo' => isset($simpanan['logo']) ? (int) $simpanan['logo'] : null];

        foreach (self::BAWAAN as $kunci => $bawaan) {
            $data[$kunci] = $kunci === 'kota' && array_key_exists($kunci, $simpanan)
                ? (string) $simpanan[$kunci]
                : (filled($simpanan[$kunci] ?? null) ? (string) $simpanan[$kunci] : $bawaan);
        }

        return $data;
    }

    public function adaLogoUnggahan(): bool
    {
        return $this->data()['logo'] !== null;
    }

    /** Alamat berkas logo/ikon: hasil unggahan (dengan penanda versi supaya browser memuat yang baru) atau bawaan. */
    public function url(string $berkas): string
    {
        $versi = $this->data()['logo'];

        if ($versi !== null && isset(self::BERKAS[$berkas])) {
            return route('identitas.berkas', $berkas).'?v='.$versi;
        }

        return asset(self::BERKAS[$berkas][1] ?? self::BERKAS['logo.png'][1]);
    }

    /**
     * @param  array{nama_usaha: string, nama_aplikasi: string, nama_singkat: string, kota: ?string}  $isian
     * @return int jumlah isian yang berubah (logo dihitung satu)
     */
    public function simpan(array $isian, ?UploadedFile $logo, bool $logoBawaan, User $oleh): int
    {
        $lama = $this->data();
        $baru = $lama;
        $rincian = [];

        foreach (array_keys(self::BAWAAN) as $kunci) {
            $nilai = Str::squish((string) ($isian[$kunci] ?? ''));

            if ($nilai !== $lama[$kunci]) {
                $rincian[] = ['label' => self::LABEL[$kunci], 'lama' => $lama[$kunci] ?: null, 'baru' => $nilai ?: null];
                $baru[$kunci] = $nilai;
            }
        }

        if ($logo !== null) {
            $this->olahLogo($logo);
            $baru['logo'] = now()->getTimestamp();
            $rincian[] = ['label' => 'Logo', 'lama' => $lama['logo'] ? 'Unggahan' : 'Bawaan', 'baru' => 'Logo baru'];
        } elseif ($logoBawaan && $lama['logo'] !== null) {
            Storage::disk(self::DISK)->deleteDirectory(self::FOLDER);
            $baru['logo'] = null;
            $rincian[] = ['label' => 'Logo', 'lama' => 'Unggahan', 'baru' => 'Bawaan'];
        }

        if ($rincian === []) {
            return 0;
        }

        KontrolSistem::query()->updateOrCreate(
            ['kunci' => self::KUNCI],
            ['nilai' => json_encode($baru, JSON_UNESCAPED_UNICODE), 'diubah_oleh' => $oleh->id],
        );
        Cache::forget(self::KUNCI_CACHE);

        $this->log->catat($oleh, LogPerubahan::KATEGORI_PENGATURAN, 'identitas.ubah', 'Identitas usaha diubah', $rincian);

        return count($rincian);
    }

    /** Jalur berkas hasil unggahan untuk route identitas.berkas, atau null bila belum ada. */
    public function jalur(string $berkas): ?string
    {
        $relatif = self::FOLDER.'/'.$berkas;

        return isset(self::BERKAS[$berkas]) && $this->adaLogoUnggahan() && Storage::disk(self::DISK)->exists($relatif)
            ? Storage::disk(self::DISK)->path($relatif)
            : null;
    }

    private function olahLogo(UploadedFile $file): void
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            throw new DomainException('Server belum bisa mengolah gambar (ekstensi GD PHP mati). Hubungi pengelola sistem.');
        }

        $isi = @file_get_contents($file->getRealPath());
        $asli = $isi === false ? false : @imagecreatefromstring($isi);

        if (! $asli instanceof \GdImage) {
            throw new DomainException('Gambar logo tidak bisa dibaca. Pakai file PNG, JPG, atau WebP.');
        }

        imagepalettetotruecolor($asli);
        $asli = $this->pangkasTepiKosong($asli);
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::FOLDER);

        foreach (self::BERKAS as $nama => [[$lebar, $tinggi, $sisi, $skala]]) {
            $gambar = $sisi === null ? $this->muatkan($asli, $lebar, $tinggi) : $this->ikon($asli, $sisi, $skala);
            ob_start();
            imagepng($gambar, null, 9);
            $disk->put(self::FOLDER.'/'.$nama, (string) ob_get_clean());
            imagedestroy($gambar);
        }

        imagedestroy($asli);
    }

    /** Logo diperkecil supaya muat di kotak lebar x tinggi, latar transparan tetap. */
    private function muatkan(\GdImage $asli, int $lebar, int $tinggi): \GdImage
    {
        [$w, $h] = $this->ukuranMuat(imagesx($asli), imagesy($asli), $lebar, $tinggi);
        $hasil = $this->kanvas($w, $h, null);
        imagecopyresampled($hasil, $asli, 0, 0, 0, 0, $w, $h, imagesx($asli), imagesy($asli));

        return $hasil;
    }

    /** Ikon persegi berlatar putih dengan logo di tengah, selebar $skala dari sisi ikon. */
    private function ikon(\GdImage $asli, int $sisi, float $skala): \GdImage
    {
        $ruang = (int) round($sisi * $skala);
        [$w, $h] = $this->ukuranMuat(imagesx($asli), imagesy($asli), $ruang, $ruang);
        $hasil = $this->kanvas($sisi, $sisi, [255, 255, 255]);
        imagecopyresampled($hasil, $asli, intdiv($sisi - $w, 2), intdiv($sisi - $h, 2), 0, 0, $w, $h, imagesx($asli), imagesy($asli));

        return $hasil;
    }

    /** @return array{0: int, 1: int} */
    private function ukuranMuat(int $w, int $h, int $maksW, int $maksH): array
    {
        $skala = min($maksW / $w, $maksH / $h, 1.0);

        return [max(1, (int) round($w * $skala)), max(1, (int) round($h * $skala))];
    }

    /** @param  array{0: int, 1: int, 2: int}|null  $latar  null = transparan */
    private function kanvas(int $w, int $h, ?array $latar): \GdImage
    {
        $kanvas = imagecreatetruecolor($w, $h);
        imagealphablending($kanvas, false);
        imagesavealpha($kanvas, true);
        $warna = $latar === null
            ? imagecolorallocatealpha($kanvas, 0, 0, 0, 127)
            : imagecolorallocate($kanvas, ...$latar);
        imagefilledrectangle($kanvas, 0, 0, $w - 1, $h - 1, $warna);
        imagealphablending($kanvas, true);

        return $kanvas;
    }

    /** Buang tepi transparan di sekeliling logo supaya logo tampil sebesar mungkin. */
    private function pangkasTepiKosong(\GdImage $gambar): \GdImage
    {
        $hasil = function_exists('imagecropauto') ? @imagecropauto($gambar, IMG_CROP_TRANSPARENT) : false;

        if ($hasil instanceof \GdImage) {
            imagedestroy($gambar);

            return $hasil;
        }

        return $gambar;
    }
}
