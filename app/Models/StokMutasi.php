<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Buku besar stok. Setiap perubahan stok dicatat sebagai satu baris (jumlah + = masuk, - = keluar).
 * Baris tidak boleh diubah selain statusnya (menunggu -> sah / batal) dan tidak boleh dihapus.
 * Pencatatan dan pengesahan hanya lewat App\Services\StokService.
 */
class StokMutasi extends Model
{
    public const STATUS_MENUNGGU = 'menunggu';
    public const STATUS_SAH = 'sah';
    public const STATUS_BATAL = 'batal';

    public const JENIS_MASUK_SUPPLIER = 'masuk_supplier';
    public const JENIS_KIRIM_KELUAR = 'kirim_keluar';
    public const JENIS_KIRIM_MASUK = 'kirim_masuk';
    public const JENIS_PAKAI_DAPUR = 'pakai_dapur';
    public const JENIS_RUSAK = 'rusak';
    public const JENIS_KOREKSI_OPNAME = 'koreksi_opname';
    public const JENIS_STOK_AWAL = 'stok_awal';
    public const JENIS_TAMBAH_LANGSUNG = 'tambah_langsung';
    public const JENIS_PENYESUAIAN = 'penyesuaian';

    public const LABEL_JENIS = [
        self::JENIS_MASUK_SUPPLIER  => 'Barang Masuk',
        self::JENIS_KIRIM_KELUAR    => 'Dikirim ke Cabang',
        self::JENIS_KIRIM_MASUK     => 'Diterima dari Gudang',
        self::JENIS_PAKAI_DAPUR     => 'Pemakaian Dapur',
        self::JENIS_RUSAK           => 'Rusak / Terbuang',
        self::JENIS_KOREKSI_OPNAME  => 'Koreksi Opname',
        self::JENIS_STOK_AWAL       => 'Stok Awal',
        self::JENIS_TAMBAH_LANGSUNG => 'Tambah Stok',
        self::JENIS_PENYESUAIAN     => 'Penyesuaian Stok',
    ];

    protected $table = 'stok_mutasi';

    const CREATED_AT = 'dibuat_pada';
    const UPDATED_AT = null;

    protected $fillable = [
        'cabang_id',
        'bahan_baku_id',
        'jumlah',
        'jenis',
        'status',
        'dokumen_tipe',
        'dokumen_id',
        'dokumen_detail_id',
        'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:3',
            'dibuat_pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StokMutasi $mutasi) {
            if (array_keys($mutasi->getDirty()) !== ['status']) {
                throw new LogicException('Mutasi stok hanya boleh diubah statusnya.');
            }

            if ($mutasi->getOriginal('status') !== self::STATUS_MENUNGGU) {
                throw new LogicException('Mutasi yang sudah sah/batal tidak boleh diubah.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Mutasi stok tidak boleh dihapus.');
        });
    }

    public function scopeSah(Builder $query): void
    {
        $query->where('status', self::STATUS_SAH);
    }

    public function scopeMenunggu(Builder $query): void
    {
        $query->where('status', self::STATUS_MENUNGGU);
    }

    public function scopeDokumen(Builder $query, string $tipe, int $dokumenId): void
    {
        $query->where('dokumen_tipe', $tipe)->where('dokumen_id', $dokumenId);
    }

    public function labelJenis(): string
    {
        return self::LABEL_JENIS[$this->jenis] ?? $this->jenis;
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
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
