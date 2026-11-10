<?php

namespace App\Services\Impor;

use LogicException;

/** Data master yang hanya bisa diekspor ke Excel (ditambah lewat form karena butuh pemeriksaan khusus). */
abstract class EksporSaja extends ImporMaster
{
    public function bisaImpor(): bool
    {
        return false;
    }

    protected function kolomWajib(): array
    {
        return [];
    }

    protected function maksNama(): int
    {
        return 0;
    }

    protected function namaAda(): array
    {
        throw new LogicException(static::class.' hanya untuk ekspor.');
    }

    protected function periksaBaris(array $isi): array
    {
        throw new LogicException(static::class.' hanya untuk ekspor.');
    }

    protected function simpanBaris(array $data): void
    {
        throw new LogicException(static::class.' hanya untuk ekspor.');
    }

    public function ringkasan(array $data): array
    {
        return [$data['nama'] ?? '', ''];
    }
}
