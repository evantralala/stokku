<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto bahan baku, menu, dan profil pengguna. Foto dari HP diputar sesuai EXIF, diperkecil ke sisi terpanjang SISI_MAKS
 * (foto profil dipotong persegi SISI_PROFIL), lalu disimpan sebagai WebP (atau JPEG bila WebP tidak tersedia) di disk public.
 * Tanpa ekstensi GD, file asli disimpan apa adanya. Foto dibaca lewat route foto (FotoController), tanpa storage:link.
 */
class FotoService
{
    public const DISK = 'public';

    public const FOLDER_BAHAN = 'foto/bahan';

    public const FOLDER_MENU = 'foto/menu';

    public const FOLDER_PROFIL = 'foto/profil';

    /** Jenis di alamat foto => folder penyimpanan. */
    public const FOLDER = [
        'bahan'  => self::FOLDER_BAHAN,
        'menu'   => self::FOLDER_MENU,
        'profil' => self::FOLDER_PROFIL,
    ];

    /** Pola nama file foto di alamat. */
    public const POLA_BERKAS = '[A-Za-z0-9]+\.(?:webp|jpg|jpeg|png)';

    public const MAKS_KB = 8192;

    public const SISI_MAKS = 800;

    public const SISI_PROFIL = 320;

    private const MUTU = 82;

    /**
     * Perubahan kolom foto dari isian form: foto baru disimpan, centang hapus mengosongkan.
     *
     * @return array{foto?: ?string}
     */
    public function perubahan(?UploadedFile $file, bool $hapus, string $folder): array
    {
        if ($file !== null) {
            return ['foto' => $this->simpan($file, $folder)];
        }

        return $hapus ? ['foto' => null] : [];
    }

    public function simpan(UploadedFile $file, string $folder): string
    {
        $gambar = $this->baca($file);

        if ($gambar === null) {
            return $file->store($folder, self::DISK);
        }

        $gambar = $this->putarSesuaiExif($gambar, $file);
        $gambar = $folder === self::FOLDER_PROFIL ? $this->potongPersegi($gambar, self::SISI_PROFIL) : $this->perkecil($gambar);
        [$isi, $ekstensi] = $this->tulis($gambar);
        imagedestroy($gambar);

        $path = $folder.'/'.Str::lower(Str::ulid()).'.'.$ekstensi;
        Storage::disk(self::DISK)->put($path, $isi);

        return $path;
    }

    public function hapus(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Semua foto yang tersimpan: foto bahan, menu, dan profil.
     *
     * @return Collection<int, string> path di disk public
     */
    public function semua(): Collection
    {
        $disk = Storage::disk(self::DISK);

        return collect(self::FOLDER)
            ->flatMap(fn (string $folder) => $disk->allFiles($folder))
            ->reject(fn (string $path) => str_starts_with(basename($path), '.'))
            ->values();
    }

    /** Hapus semua foto yang tersimpan (dipakai saat database dikosongkan dengan migrate:fresh). */
    public function hapusSemua(): int
    {
        $foto = $this->semua();
        Storage::disk(self::DISK)->delete($foto->all());

        return $foto->count();
    }

    public static function url(?string $path): ?string
    {
        $jenis = $path ? array_search(dirname($path), self::FOLDER, true) : false;

        return $jenis === false ? null : route('foto', [$jenis, basename($path)], false);
    }

    private function baca(UploadedFile $file): ?\GdImage
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $isi = @file_get_contents($file->getRealPath());
        $gambar = $isi === false ? false : @imagecreatefromstring($isi);

        return $gambar instanceof \GdImage ? $gambar : null;
    }

    private function putarSesuaiExif(\GdImage $gambar, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $gambar;
        }

        $exif = @exif_read_data($file->getRealPath());
        $sudut = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($sudut === 0) {
            return $gambar;
        }

        $diputar = imagerotate($gambar, $sudut, 0);

        if ($diputar === false) {
            return $gambar;
        }

        imagedestroy($gambar);

        return $diputar;
    }

    private function perkecil(\GdImage $gambar): \GdImage
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $sisi = max($lebar, $tinggi);

        if ($sisi <= self::SISI_MAKS) {
            return $gambar;
        }

        $skala = self::SISI_MAKS / $sisi;
        $baru = imagescale($gambar, max(1, (int) round($lebar * $skala)), max(1, (int) round($tinggi * $skala)), IMG_BICUBIC);

        if ($baru === false) {
            return $gambar;
        }

        imagedestroy($gambar);

        return $baru;
    }

    /** Potong bagian tengah menjadi persegi, lalu perkecil ke $sisi (tidak diperbesar). */
    private function potongPersegi(\GdImage $gambar, int $sisi): \GdImage
    {
        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $potong = min($lebar, $tinggi);
        $hasilSisi = min($sisi, $potong);

        $baru = imagecreatetruecolor($hasilSisi, $hasilSisi);
        imagealphablending($baru, false);
        imagesavealpha($baru, true);
        imagefill($baru, 0, 0, imagecolorallocatealpha($baru, 0, 0, 0, 127));
        imagecopyresampled($baru, $gambar, 0, 0, intdiv($lebar - $potong, 2), intdiv($tinggi - $potong, 2), $hasilSisi, $hasilSisi, $potong, $potong);
        imagedestroy($gambar);

        return $baru;
    }

    /** @return array{0: string, 1: string} isi file dan ekstensinya */
    private function tulis(\GdImage $gambar): array
    {
        if (function_exists('imagewebp')) {
            imagepalettetotruecolor($gambar);
            imagealphablending($gambar, true);
            imagesavealpha($gambar, true);
            ob_start();
            imagewebp($gambar, null, self::MUTU);

            return [(string) ob_get_clean(), 'webp'];
        }

        $latar = imagecreatetruecolor(imagesx($gambar), imagesy($gambar));
        imagefill($latar, 0, 0, imagecolorallocate($latar, 255, 255, 255));
        imagecopy($latar, $gambar, 0, 0, 0, 0, imagesx($gambar), imagesy($gambar));
        ob_start();
        imagejpeg($latar, null, self::MUTU);
        imagedestroy($latar);

        return [(string) ob_get_clean(), 'jpg'];
    }
}
