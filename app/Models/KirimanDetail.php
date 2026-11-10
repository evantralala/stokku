<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu barang dalam kiriman. qty_kirim dan qty_terima dalam satuan dasar.
 * qty_terima = jumlah yang diterima cabang dalam keadaan layak pakai.
 */
class KirimanDetail extends Model
{
    use SoftDeletes;

    public const KONDISI_BAIK = 'baik';
    public const KONDISI_RUSAK = 'rusak';
    public const KONDISI_TIDAK_ADA = 'tidak_ada';

    public const LABEL_KONDISI = [
        self::KONDISI_BAIK        => 'Baik Semua',
        self::KONDISI_RUSAK       => 'Ada yang Rusak',
        self::KONDISI_TIDAK_ADA   => 'Barang Tidak Ada',
    ];

    protected $table = 'kiriman_detail';

    protected $fillable = [
        'kiriman_id',
        'bahan_baku_id',
        'qty_kirim',
        'qty_terima',
        'kondisi',
        'catatan_penerima',
        'dichecklist_oleh',
        'dichecklist_pada',
    ];

    protected function casts(): array
    {
        return [
            'qty_kirim' => 'decimal:3',
            'qty_terima' => 'decimal:3',
            'dichecklist_pada' => 'datetime',
        ];
    }

    public function kiriman(): BelongsTo
    {
        return $this->belongsTo(Kiriman::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function pemeriksa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dichecklist_oleh');
    }

    public function sudahDichecklist(): bool
    {
        return $this->dichecklist_pada !== null;
    }

    /** $detail->selisih: terima - kirim (satuan dasar), null bila belum dihitung. */
    protected function selisih(): Attribute
    {
        return Attribute::get(function () {
            if ($this->qty_kirim === null || $this->qty_terima === null) {
                return null;
            }

            return round((float) $this->qty_terima - (float) $this->qty_kirim, 3);
        });
    }

    /** Selisih melebihi toleransi bahan (persen dari jumlah kirim). */
    public function selisihDiLuarToleransi(float $toleransiPersen): bool
    {
        $selisih = $this->selisih;

        if ($selisih === null || $selisih == 0.0) {
            return false;
        }

        $kirim = (float) $this->qty_kirim;

        return $kirim <= 0 || abs($selisih) / $kirim * 100 > $toleransiPersen;
    }
}
