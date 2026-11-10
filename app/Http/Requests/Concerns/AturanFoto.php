<?php

namespace App\Http\Requests\Concerns;

use App\Services\FotoService;

/** Isian foto pada form data master: file foto (opsional) dan centang hapus foto. */
trait AturanFoto
{
    protected function aturanFoto(): array
    {
        return [
            'foto'       => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.FotoService::MAKS_KB],
            'hapus_foto' => ['nullable', 'boolean'],
        ];
    }

    protected function pesanFoto(): array
    {
        return [
            'foto.image'    => 'File foto harus berupa gambar.',
            'foto.mimes'    => 'Foto harus JPG, PNG, atau WebP.',
            'foto.max'      => 'Foto maksimal '.(FotoService::MAKS_KB / 1024).' MB.',
            'foto.uploaded' => 'Foto gagal diunggah. Coba foto yang lebih kecil.',
        ];
    }

    /** Data tervalidasi tanpa isian foto (foto diolah FotoService). */
    public function dataTanpaFoto(): array
    {
        return collect($this->validated())->except(['foto', 'hapus_foto'])->all();
    }
}
