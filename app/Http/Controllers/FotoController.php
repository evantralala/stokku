<?php

namespace App\Http\Controllers;

use App\Services\FotoService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Foto bahan baku dan menu dibaca lewat aplikasi (bukan symlink public/storage), supaya jalan di shared hosting. */
class FotoController extends Controller
{
    /** Nama file foto selalu baru setiap diganti, jadi browser boleh menyimpannya lama. */
    private const CACHE_DETIK = 2592000;

    public function __invoke(string $jenis, string $berkas): StreamedResponse
    {
        $path = FotoService::FOLDER[$jenis].'/'.$berkas;
        $disk = Storage::disk(FotoService::DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age='.self::CACHE_DETIK.', immutable',
        ]);
    }
}
