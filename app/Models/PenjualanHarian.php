<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Penjualan satu cabang dalam satu hari (jumlah porsi per menu), dicatat Admin Cabang atau diimpor dari Excel.
 * Pemakaian bahan dihitung dari resep yang berlaku di tanggal itu dan dicatat sebagai
 * barang keluar jenis pemakaian (barang_keluar_id).
 */
class PenjualanHarian extends Model
{
    use SoftDeletes;

    /** Penjualan boleh dicatat paling lama sekian hari ke belakang. */
    public const BATAS_MUNDUR_HARI = 7;

    /** Batas tanggal ke belakang untuk impor Excel penjualan oleh Admin Utama. */
    public const BATAS_MUNDUR_IMPOR_HARI = 31;

    /** Penjualan masih boleh diubah sampai sekian hari setelah tanggalnya. */
    public const BATAS_UBAH_HARI = 1;

    protected $table = 'penjualan_harian';

    protected $fillable = [
        'cabang_id',
        'tanggal',
        'barang_keluar_id',
        'dicatat_oleh',
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

    public function barangKeluar(): BelongsTo
    {
        return $this->belongsTo(BarangKeluar::class);
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    /** Terakhir dicatat atau diubah oleh Admin Cabang (bukan impor Admin Utama). */
    public function dicatatCabang(): bool
    {
        return User::query()->whereKey($this->dicatat_oleh)->where('role', User::ROLE_ADMIN_CABANG)->exists();
    }

    public function detail(): HasMany
    {
        return $this->hasMany(PenjualanDetail::class);
    }

    public function scopeDiLokasi(Builder $query, int $lokasiId): void
    {
        $query->where('cabang_id', $lokasiId);
    }

    /** Tanggal terakhir penjualan ini masih boleh diubah. */
    public function batasUbah(): Carbon
    {
        return $this->tanggal->copy()->addDays(self::BATAS_UBAH_HARI)->endOfDay();
    }

    /** Admin Cabang hanya boleh mengubah catatannya sendiri sebelum batas waktu; hasil impor Admin Utama tidak bisa diubah cabang. */
    public function bisaDiubah(): bool
    {
        return now()->lessThanOrEqualTo($this->batasUbah()) && $this->dicatatCabang();
    }

    public function namaPencatat(): string
    {
        return $this->pencatat?->nama ?? '—';
    }
}
