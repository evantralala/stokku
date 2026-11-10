<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Satu baris barang masuk. qty disimpan dalam satuan dasar bahan. */
class BarangMasukDetail extends Model
{
    use SoftDeletes;

    protected $table = 'barang_masuk_detail';

    protected $fillable = [
        'barang_masuk_id',
        'bahan_baku_id',
        'qty',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function barangMasuk(): BelongsTo
    {
        return $this->belongsTo(BarangMasuk::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function mutasi(): HasOne
    {
        return $this->hasOne(StokMutasi::class, 'dokumen_detail_id')
            ->where('dokumen_tipe', BarangMasuk::DOKUMEN_TIPE);
    }
}
