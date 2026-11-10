<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stock opname satu lokasi. Selama berjalan atau menunggu keputusan, stok lokasi dikunci
 * (cabang.opname_aktif) sehingga stok sistem tidak bergerak. Setelah disetujui Admin Utama,
 * mutasi koreksi_opname menyamakan stok sistem dengan hasil hitung fisik.
 */
class Opname extends Model
{
    use SoftDeletes;

    public const DOKUMEN_TIPE = 'opname';
    public const AWALAN_NOMOR = 'OP';

    public const STATUS_BERJALAN = 'berjalan';
    public const STATUS_DIKIRIM = 'dikirim';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_BATAL = 'batal';

    /** [label, kelas badge] per status. */
    public const TAMPILAN_STATUS = [
        self::STATUS_BERJALAN  => ['Sedang Dihitung', 'b-jalan'],
        self::STATUS_DIKIRIM   => ['Menunggu Persetujuan', 'b-tunggu'],
        self::STATUS_DISETUJUI => ['Disetujui', 'b-setuju'],
        self::STATUS_BATAL     => ['Dibatalkan', 'b-pasif'],
    ];

    protected $table = 'opname';

    protected $fillable = [
        'nomor',
        'cabang_id',
        'tanggal',
        'petugas_id',
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

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(OpnameDetail::class);
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

    /** Opname yang masih mengunci stok (sedang dihitung atau menunggu keputusan). */
    public function scopeBelumSelesai(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_BERJALAN, self::STATUS_DIKIRIM]);
    }

    public function sedangDihitung(): bool
    {
        return $this->status === self::STATUS_BERJALAN;
    }

    public function tampilanStatus(): array
    {
        return self::TAMPILAN_STATUS[$this->status] ?? [$this->status, 'b-draf'];
    }
}
