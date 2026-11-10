<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kategori bahan baku, mis. Sayur, Daging, Bumbu. */
class Kategori extends Model
{
    use DataMaster;

    protected $table = 'kategori';

    protected $fillable = [
        'nama',
        'urutan',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    public function bahanBaku(): HasMany
    {
        return $this->hasMany(BahanBaku::class);
    }

    public function sudahDipakai(): bool
    {
        return $this->bahanBaku()->exists();
    }

    /** Urutan untuk kategori baru: setelah yang terakhir. */
    public static function urutanBerikutnya(): int
    {
        return (int) static::query()->max('urutan') + 1;
    }
}
