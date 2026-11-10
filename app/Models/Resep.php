<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu versi resep = semua baris satu menu dengan berlaku_sejak yang sama.
 * Mengubah resep berarti membuat versi baru, baris versi lama tidak diubah.
 */
class Resep extends Model
{
    protected $table = 'resep';

    protected $fillable = [
        'menu_id',
        'bahan_baku_id',
        'jumlah',
        'catatan',
        'berlaku_sejak',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:3',
            'berlaku_sejak' => 'date',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }
}
