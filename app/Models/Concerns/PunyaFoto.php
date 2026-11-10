<?php

namespace App\Models\Concerns;

use App\Services\FotoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Model dengan kolom foto (path di disk public). File lama dibuang setelah foto diganti, dikosongkan,
 * atau datanya dihapus, dan hanya setelah transaksinya berhasil.
 */
trait PunyaFoto
{
    public static function bootPunyaFoto(): void
    {
        static::updated(function (Model $model) {
            if ($model->wasChanged('foto')) {
                $lama = $model->getOriginal('foto');
                DB::afterCommit(fn () => app(FotoService::class)->hapus($lama));
            }
        });

        static::deleted(function (Model $model) {
            $foto = $model->foto;
            DB::afterCommit(fn () => app(FotoService::class)->hapus($foto));
        });
    }

    public function urlFoto(): ?string
    {
        return FotoService::url($this->foto);
    }
}
