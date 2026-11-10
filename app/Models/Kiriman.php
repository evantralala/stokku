<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kiriman barang dari Gudang Pusat ke cabang, berdasarkan permintaan yang disetujui.
 * Stok pusat berkurang saat dikirim; stok cabang bertambah saat cabang menyelesaikan checklist terima.
 */
class Kiriman extends Model
{
    use SoftDeletes;

    public const DOKUMEN_TIPE = 'kiriman';
    public const AWALAN_NOMOR = 'KR';

    public const STATUS_DISIAPKAN = 'disiapkan';
    public const STATUS_DIKIRIM = 'dikirim';
    /** Sudah diterima cabang, tetapi ada selisih di luar toleransi. */
    public const STATUS_DICHECKLIST = 'dichecklist';
    public const STATUS_SELESAI = 'selesai';

    /** [label, kelas badge] per status. */
    public const TAMPILAN_STATUS = [
        self::STATUS_DISIAPKAN   => ['Disiapkan', 'b-draf'],
        self::STATUS_DIKIRIM     => ['Dalam Perjalanan', 'b-jalan'],
        self::STATUS_DICHECKLIST => ['Diterima, Ada Selisih', 'b-selisih'],
        self::STATUS_SELESAI     => ['Diterima Sesuai', 'b-cocok'],
    ];

    protected $table = 'kiriman';

    protected $fillable = [
        'nomor',
        'dari_cabang_id',
        'ke_cabang_id',
        'permintaan_id',
        'dikirim_oleh',
        'dikirim_pada',
        'sampai_pada',
        'catatan_pusat',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'dikirim_pada' => 'datetime',
            'sampai_pada' => 'datetime',
        ];
    }

    public function dariCabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'dari_cabang_id');
    }

    public function keCabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'ke_cabang_id');
    }

    public function permintaan(): BelongsTo
    {
        return $this->belongsTo(Permintaan::class);
    }

    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikirim_oleh');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(KirimanDetail::class);
    }

    public function scopeKe(Builder $query, int $lokasiId): void
    {
        $query->where('ke_cabang_id', $lokasiId);
    }

    public function scopeDari(Builder $query, int $lokasiId): void
    {
        $query->where('dari_cabang_id', $lokasiId);
    }

    public function menungguDiterima(): bool
    {
        return $this->status === self::STATUS_DIKIRIM;
    }

    public function tampilanStatus(): array
    {
        return self::TAMPILAN_STATUS[$this->status] ?? [$this->status, 'b-draf'];
    }
}
