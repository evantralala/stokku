<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Dokumen barang masuk ke Gudang Pusat. Stok bertambah setelah disetujui Admin Utama. */
class BarangMasuk extends Model
{
    use SoftDeletes;

    public const DOKUMEN_TIPE = 'barang_masuk';
    public const AWALAN_NOMOR = 'BM';

    public const STATUS_MENUNGGU = 'menunggu';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_REVISI = 'revisi';
    public const STATUS_BATAL = 'batal';

    /** [label, kelas badge] per status. */
    public const TAMPILAN_STATUS = [
        self::STATUS_MENUNGGU  => ['Menunggu Persetujuan', 'b-tunggu'],
        self::STATUS_DISETUJUI => ['Disetujui', 'b-setuju'],
        self::STATUS_REVISI    => ['Perlu Diperbaiki', 'b-tolak'],
        self::STATUS_BATAL     => ['Dibatalkan', 'b-pasif'],
    ];

    protected $table = 'barang_masuk';

    protected $fillable = [
        'nomor',
        'cabang_id',
        'tanggal',
        'catatan',
        'dicatat_oleh',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(BarangMasukDetail::class);
    }

    public function persetujuan(): HasMany
    {
        return $this->hasMany(Persetujuan::class, 'dokumen_id')
            ->where('dokumen_tipe', self::DOKUMEN_TIPE);
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(StokMutasi::class, 'dokumen_id')
            ->where('dokumen_tipe', self::DOKUMEN_TIPE);
    }

    public function scopeDiLokasi(Builder $query, int $lokasiId): void
    {
        $query->where('cabang_id', $lokasiId);
    }

    /** Masih boleh diubah atau dibatalkan pencatat (belum diputuskan Admin Utama, atau dikembalikan untuk diperbaiki). */
    public function bisaDiubah(): bool
    {
        return in_array($this->status, [self::STATUS_MENUNGGU, self::STATUS_REVISI], true);
    }

    protected function labelStatus(): Attribute
    {
        return Attribute::get(fn () => self::TAMPILAN_STATUS[$this->status][0] ?? $this->status);
    }

    protected function kelasStatus(): Attribute
    {
        return Attribute::get(fn () => self::TAMPILAN_STATUS[$this->status][1] ?? 'b-draf');
    }
}
