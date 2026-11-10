<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tambah stok langsung satu bahan di satu lokasi, tanpa persetujuan. Mutasinya berjenis tambah_langsung dan langsung sah. */
class TambahStok extends Model
{
    use SoftDeletes;

    public const DOKUMEN_TIPE = 'tambah_stok';
    public const AWALAN_NOMOR = 'TS';

    protected $table = 'tambah_stok';

    protected $fillable = [
        'nomor',
        'cabang_id',
        'bahan_baku_id',
        'jumlah',
        'keterangan',
        'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'jumlah'              => 'decimal:3',
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
