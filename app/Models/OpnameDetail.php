<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu bahan dalam opname, semua jumlah dalam satuan dasar.
 * qty_sistem adalah salinan stok sistem saat opname dimulai dan tidak pernah ditampilkan ke petugas.
 * Kolom selisih (fisik - sistem) dihitung otomatis oleh database.
 */
class OpnameDetail extends Model
{
    use SoftDeletes;

    protected $table = 'opname_detail';

    protected $fillable = [
        'opname_id',
        'bahan_baku_id',
        'qty_fisik',
        'qty_sistem',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'qty_fisik' => 'decimal:3',
            'qty_sistem' => 'decimal:3',
            'selisih' => 'decimal:3',
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(Opname::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function mutasi(): HasOne
    {
        return $this->hasOne(StokMutasi::class, 'dokumen_detail_id')
            ->where('dokumen_tipe', Opname::DOKUMEN_TIPE);
    }

    public function sudahDihitung(): bool
    {
        return $this->qty_fisik !== null;
    }

    /** Persentase selisih terhadap stok sistem; null bila belum dihitung. */
    public function persenSelisih(): ?float
    {
        if ($this->qty_fisik === null) {
            return null;
        }

        $sistem = (float) $this->qty_sistem;
        $selisih = (float) $this->qty_fisik - $sistem;

        if ($sistem == 0.0) {
            return $selisih == 0.0 ? 0.0 : 100.0;
        }

        return round(abs($selisih) / $sistem * 100, 1);
    }

    public function lewatToleransi(): bool
    {
        $persen = $this->persenSelisih();

        return $persen !== null && $persen > (float) $this->bahanBaku->toleransi_persen;
    }
}
