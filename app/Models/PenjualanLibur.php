<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hari libur satu cabang: tanggal tanpa penjualan yang ditandai libur. Tidak dianggap "penjualan belum dicatat".
 * Hilang sendiri bila penjualan tanggal itu dicatat (cabang ternyata buka).
 */
class PenjualanLibur extends Model
{
    protected $table = 'penjualan_libur';

    protected $fillable = [
        'cabang_id',
        'tanggal',
        'dicatat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function scopeDiLokasi(Builder $query, int $lokasiId): void
    {
        $query->where('cabang_id', $lokasiId);
    }
}
