<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu barang dalam permintaan. Semua jumlah dalam satuan dasar.
 * sisa_saat_itu dan minimum_saat_itu adalah salinan kondisi stok cabang saat diajukan.
 */
class PermintaanDetail extends Model
{
    use SoftDeletes;

    public const STATUS_MENUNGGU = 'menunggu';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_OTOMATIS_DITOLAK = 'otomatis_ditolak';

    /** [label, kelas badge] per status. */
    public const TAMPILAN_STATUS = [
        self::STATUS_MENUNGGU         => ['Menunggu', 'b-tunggu'],
        self::STATUS_DISETUJUI        => ['Disetujui', 'b-setuju'],
        self::STATUS_DITOLAK          => ['Ditolak', 'b-tolak'],
        self::STATUS_OTOMATIS_DITOLAK => ['Ditolak Otomatis', 'b-tolak'],
    ];

    protected $table = 'permintaan_detail';

    protected $fillable = [
        'permintaan_id',
        'bahan_baku_id',
        'qty_diminta',
        'qty_disetujui',
        'qty_dikirim',
        'sisa_saat_itu',
        'minimum_saat_itu',
        'status',
        'alasan',
    ];

    protected function casts(): array
    {
        return [
            'qty_diminta' => 'decimal:3',
            'qty_disetujui' => 'decimal:3',
            'qty_dikirim' => 'decimal:3',
            'sisa_saat_itu' => 'decimal:3',
            'minimum_saat_itu' => 'decimal:3',
        ];
    }

    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(Permintaan::class);
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function persetujuan(): HasMany
    {
        return $this->hasMany(Persetujuan::class, 'dokumen_detail_id')
            ->where('dokumen_tipe', Permintaan::DOKUMEN_TIPE);
    }

    /** $detail->utang_kiriman: jumlah disetujui yang belum dikirim pusat. */
    protected function utangKiriman(): Attribute
    {
        return Attribute::get(fn () => max(
            0,
            round((float) $this->qty_disetujui - (float) $this->qty_dikirim, 3)
        ));
    }

    public function scopeMasihUtang(Builder $query): void
    {
        $query->where('status', self::STATUS_DISETUJUI)
            ->whereColumn('qty_dikirim', '<', 'qty_disetujui');
    }

    public function tampilanStatus(): array
    {
        return self::TAMPILAN_STATUS[$this->status] ?? [$this->status, 'b-draf'];
    }
}
