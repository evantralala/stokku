<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Lokasi stok: Gudang Pusat atau cabang. */
class Cabang extends Model
{
    use DataMaster;

    public const JENIS_PUSAT = 'pusat';
    public const JENIS_CABANG = 'cabang';

    /** Nama jenis yang ditampilkan di layar. */
    public const LABEL_JENIS = [
        self::JENIS_PUSAT  => 'Gudang Pusat',
        self::JENIS_CABANG => 'Cabang',
    ];

    /**
     * Tabel dan kolom yang merujuk ke lokasi; dipakai untuk menentukan boleh dihapus atau tidak.
     * Stok minimum tidak termasuk karena ikut dihapus bersama lokasinya.
     */
    private const RUJUKAN = [
        'users'              => ['cabang_id'],
        'stok_saldo'         => ['cabang_id'],
        'stok_mutasi'        => ['cabang_id'],
        'permintaan'         => ['cabang_id'],
        'kiriman'            => ['dari_cabang_id', 'ke_cabang_id'],
        'barang_masuk'       => ['cabang_id'],
        'barang_keluar'      => ['cabang_id'],
        'penjualan_harian'   => ['cabang_id'],
        'opname'             => ['cabang_id'],
    ];

    protected $table = 'cabang';

    protected $fillable = [
        'kode',
        'nama',
        'jenis',
        'alamat',
        'opname_aktif',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'opname_aktif' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (Cabang $lokasi) => $lokasi->minimum()->delete());
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function minimum(): HasMany
    {
        return $this->hasMany(BahanBakuMinimum::class);
    }

    /** Gudang Pusat dikenali dari jenis, bukan dari id atau kode. */
    public function scopePusat(Builder $query): void
    {
        $query->where($this->qualifyColumn('jenis'), self::JENIS_PUSAT);
    }

    public function scopeCabang(Builder $query): void
    {
        $query->where($this->qualifyColumn('jenis'), self::JENIS_CABANG);
    }

    public function isPusat(): bool
    {
        return $this->jenis === self::JENIS_PUSAT;
    }

    /** $lokasi->label_jenis -> "Gudang Pusat" */
    protected function labelJenis(): Attribute
    {
        return Attribute::get(fn () => self::LABEL_JENIS[$this->jenis] ?? $this->jenis);
    }

    public function kolomUnik(): array
    {
        return ['kode', 'nama'];
    }

    public function kolomCari(): array
    {
        return ['kode', 'nama', 'alamat'];
    }

    public function sudahDipakai(): bool
    {
        foreach (self::RUJUKAN as $tabel => $kolom) {
            $dipakai = DB::table($tabel)
                ->where(function ($q) use ($kolom) {
                    foreach ($kolom as $k) {
                        $q->orWhere($k, $this->id);
                    }
                })
                ->exists();

            if ($dipakai) {
                return true;
            }
        }

        return false;
    }

    /** Gudang Pusat aktif selain lokasi ini (aturan: hanya boleh satu). */
    public static function pusatAktifSelain(?int $kecualiId): ?self
    {
        return static::query()
            ->pusat()
            ->aktif()
            ->when($kecualiId, fn (Builder $q) => $q->whereKeyNot($kecualiId))
            ->first();
    }
}
