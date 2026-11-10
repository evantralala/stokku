<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu barang keluar. qty dalam satuan dasar.
 * Kolom penjelasan berisi alasan barang rusak, atau keterangan pemakaian dari penjualan.
 */
class BarangKeluarDetail extends Model
{
    use SoftDeletes;

    public const SEBAB_DIPAKAI = 'dipakai';
    public const SEBAB_RUSAK = 'rusak';

    protected $table = 'barang_keluar_detail';

    protected $fillable = [
        'barang_keluar_id',
        'bahan_baku_id',
        'qty',
        'sebab',
        'penjelasan',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function barangKeluar(): BelongsTo
    {
        return $this->belongsTo(BarangKeluar::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function mutasi(): HasOne
    {
        return $this->hasOne(StokMutasi::class, 'dokumen_detail_id')
            ->where('dokumen_tipe', BarangKeluar::DOKUMEN_TIPE);
    }
}
