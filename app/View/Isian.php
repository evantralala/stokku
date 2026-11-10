<?php

namespace App\View;

use Illuminate\Support\ViewErrorBag;

/**
 * Data bantu untuk komponen isian form (resources/views/components/form/*):
 * id elemen, kunci error, dan atribut aksesibilitas.
 */
final class Isian
{
    public readonly string $id;

    /** Kunci dot notation untuk old() dan $errors, mis. "baris.0.jumlah". */
    public readonly string $kunci;

    public readonly ?string $pesanGalat;

    public readonly ?string $idBantu;

    public readonly string $idGalat;

    private function __construct(string $nama, ?string $id, ?string $bantu, ViewErrorBag $errors)
    {
        $this->kunci = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $nama), '.');
        $this->id = $id ?? str_replace('.', '_', $this->kunci);
        $this->pesanGalat = $errors->first($this->kunci) ?: null;
        $this->idBantu = $bantu ? "bantu-{$this->id}" : null;
        $this->idGalat = "galat-{$this->id}";
    }

    public static function dari(string $nama, ?string $id, ?string $bantu, ViewErrorBag $errors): self
    {
        return new self($nama, $id, $bantu, $errors);
    }

    public function adaGalat(): bool
    {
        return $this->pesanGalat !== null;
    }

    /** Atribut tambahan untuk elemen input. Nilai null tidak ikut ditulis. */
    public function atribut(bool $wajib): array
    {
        $dijelaskan = trim(($this->idBantu ?? '').' '.($this->adaGalat() ? $this->idGalat : ''));

        return [
            'id' => $this->id,
            'aria-invalid' => $this->adaGalat() ? 'true' : null,
            'aria-required' => $wajib ? 'true' : null,
            'aria-describedby' => $dijelaskan !== '' ? $dijelaskan : null,
        ];
    }
}
