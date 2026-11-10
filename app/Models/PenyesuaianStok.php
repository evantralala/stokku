<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penyesuaian stok satu bahan di satu lokasi: stok disamakan dengan jumlah sebenarnya, tanpa persetujuan.
 * Mutasinya berjenis penyesuaian sebesar selisih dan langsung sah. Semua jumlah dalam satuan dasar.
 */
class PenyesuaianStok extends Model
{
    use SoftDeletes;

    public const DOKUMEN_TIPE = 'penyesuaian_stok';
    public const AWALAN_NOMOR = 'PS';

    protected $table = 'penyesuaian_stok';

    protected $fillable = [
        'nomor',
        'cabang_id',
        'bahan_baku_id',
        'stok_lama',
        'stok_baru',
        'selisih',
        'keterangan',
        'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'stok_lama'           => 'decimal:3',
            'stok_baru'           => 'decimal:3',
            'selisih'             => 'decimal:3',
        ];
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'cabang_id');
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
