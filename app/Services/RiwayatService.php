<?php

namespace App\Services;

use App\Models\LogPerubahan;
use App\Support\DaftarRiwayat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Riwayat perubahan satu fitur (atau satu data) dari log perubahan, sesuai DaftarRiwayat::JENIS. */
class RiwayatService
{
    public const PER_HALAMAN = 30;

    public function daftar(string $jenis, ?int $objekId = null): LengthAwarePaginator
    {
        $aturan = DaftarRiwayat::JENIS[$jenis];
        $model = $aturan['model'] ?? null;

        return LogPerubahan::query()
            ->with('oleh:id,nama')
            ->where(function (Builder $q) use ($aturan, $model, $objekId) {
                if ($model) {
                    $q->orWhere(fn (Builder $q) => $q->where('objek_tipe', class_basename($model))
                        ->when($objekId, fn (Builder $q, int $id) => $q->where('objek_id', $id)));
                }

                if ($objekId) {
                    return;
                }

                foreach ($aturan['aksi'] ?? [] as $awalan) {
                    $q->orWhere('aksi', 'like', $awalan.'%');
                }

                if (isset($aturan['kategori'])) {
                    $q->orWhereIn('kategori', $aturan['kategori']);
                }
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_HALAMAN)
            ->withQueryString();
    }
}
