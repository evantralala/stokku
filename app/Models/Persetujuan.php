<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Catatan keputusan Admin Utama (atau sistem) atas dokumen. Tidak boleh diubah atau dihapus;
 * keputusan baru selalu ditambahkan sebagai baris baru.
 * dokumen_detail_id diisi bila keputusan per barang (permintaan), kosong bila per dokumen.
 */
class Persetujuan extends Model
{
    public const KEPUTUSAN_DISETUJUI = 'disetujui';
    public const KEPUTUSAN_DITOLAK = 'ditolak';
    public const KEPUTUSAN_OTOMATIS_DITOLAK = 'otomatis_ditolak';
    public const KEPUTUSAN_REVISI = 'revisi';

    public const LABEL_KEPUTUSAN = [
        self::KEPUTUSAN_DISETUJUI        => 'Disetujui',
        self::KEPUTUSAN_DITOLAK          => 'Ditolak',
        self::KEPUTUSAN_OTOMATIS_DITOLAK => 'Ditolak Otomatis',
        self::KEPUTUSAN_REVISI           => 'Diminta Perbaikan',
    ];

    protected $table = 'persetujuan';

    const CREATED_AT = 'pada';
    const UPDATED_AT = null;

    protected $fillable = [
        'dokumen_tipe',
        'dokumen_id',
        'dokumen_detail_id',
        'keputusan',
        'qty_disetujui',
        'catatan',
        'oleh_user_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_disetujui' => 'decimal:3',
            'pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Keputusan tidak boleh diubah. Tambahkan keputusan baru.');
        });

        static::deleting(function () {
            throw new LogicException('Keputusan tidak boleh dihapus.');
        });
    }

    public function oleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh_user_id');
    }

    public function otomatis(): bool
    {
        return $this->oleh_user_id === null;
    }

    public function labelKeputusan(): string
    {
        return self::LABEL_KEPUTUSAN[$this->keputusan] ?? $this->keputusan;
    }

    public function scopeUntuk(Builder $query, string $tipe, int $dokumenId): void
    {
        $query->where('dokumen_tipe', $tipe)->where('dokumen_id', $dokumenId);
    }
}
