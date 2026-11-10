<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satuan ukur bahan baku, mis. gram, kg, ml, pcs. */
class Satuan extends Model
{
    use DataMaster;

    protected $table = 'satuan';

    protected $fillable = [
        'nama',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    public function bahanBakuDasar(): HasMany
    {
        return $this->hasMany(BahanBaku::class, 'satuan_dasar_id');
    }

    public function bahanBakuBeli(): HasMany
    {
        return $this->hasMany(BahanBaku::class, 'satuan_beli_id');
    }

    public function sudahDipakai(): bool
    {
        return $this->bahanBakuDasar()->exists() || $this->bahanBakuBeli()->exists();
    }
}
