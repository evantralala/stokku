<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Permintaan bahan dari cabang ke Gudang Pusat. Admin Utama memutuskan per barang;
 * barang yang disetujui kemudian dikirim pusat lewat dokumen kiriman.
 */
class Permintaan extends Model
{
    use SoftDeletes;

    public const DOKUMEN_TIPE = 'permintaan';
    public const AWALAN_NOMOR = 'PM';

    public const STATUS_MENUNGGU = 'menunggu';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_BATAL = 'batal';

    public const SUMBER_MANUAL = 'manual';
    public const SUMBER_OTOMATIS = 'otomatis';

    protected $table = 'permintaan';

    protected $fillable = [
        'nomor',
        'cabang_id',
        'diajukan_oleh',
        'diajukan_pada',
        'batas_keputusan',
        'sumber',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'diajukan_pada' => 'datetime',
            'batas_keputusan' => 'datetime',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(PermintaanDetail::class);
    }

    public function kiriman(): HasMany
    {
        return $this->hasMany(Kiriman::class);
    }

    public function scopeDiLokasi(Builder $query, int $lokasiId): void
    {
        $query->where('cabang_id', $lokasiId);
    }

    public function otomatis(): bool
    {
        return $this->sumber === self::SUMBER_OTOMATIS;
    }

    public function lewatBatas(): bool
    {
        return $this->batas_keputusan !== null && $this->batas_keputusan->isPast();
    }

    /** Masih bisa dibatalkan cabang: belum ada barang yang diputuskan Admin Utama. */
    public function bisaDibatalkan(): bool
    {
        return $this->status === self::STATUS_MENUNGGU
            && ! $this->detail()->where('status', '!=', PermintaanDetail::STATUS_MENUNGGU)->exists();
    }

    /**
     * [label, kelas badge] untuk ditampilkan.
     * Butuh kolom hitung "detail_menunggu_count" (withCount) bila tersedia, supaya tidak query per baris.
     */
    public function tampilanStatus(): array
    {
        return match ($this->status) {
            self::STATUS_BATAL   => ['Dibatalkan', 'b-pasif'],
            self::STATUS_SELESAI => ['Selesai', 'b-selesai'],
            default              => $this->jumlahMenunggu() > 0
                ? ['Menunggu Persetujuan', 'b-tunggu']
                : ['Diproses Gudang Pusat', 'b-jalan'],
        };
    }

    private function jumlahMenunggu(): int
    {
        if (array_key_exists('detail_menunggu_count', $this->attributes)) {
            return (int) $this->attributes['detail_menunggu_count'];
        }

        return $this->detail()->where('status', PermintaanDetail::STATUS_MENUNGGU)->count();
    }
}
