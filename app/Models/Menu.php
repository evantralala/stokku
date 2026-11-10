<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use App\Models\Concerns\PunyaFoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Menu extends Model
{
    use DataMaster;
    use PunyaFoto;

    protected $table = 'menu';

    protected $fillable = [
        'nama',
        'foto',
        'kategori_menu_id',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Resep menu yang belum pernah terjual tidak dipakai laporan mana pun, jadi ikut dihapus.
        static::deleting(fn (Menu $menu) => $menu->resep()->delete());
    }

    public function kategoriMenu(): BelongsTo
    {
        return $this->belongsTo(KategoriMenu::class);
    }

    public function resep(): HasMany
    {
        return $this->hasMany(Resep::class);
    }

    public function penjualanDetail(): HasMany
    {
        return $this->hasMany(PenjualanDetail::class);
    }

    /** Menu yang tidak punya resep berlaku hari ini (penjualannya belum bisa memotong stok). */
    public function scopeTanpaResepBerlaku(Builder $query): void
    {
        $query->whereDoesntHave('resep', fn (Builder $q) => $q->whereDate('berlaku_sejak', '<=', now()->toDateString()));
    }

    public function kolomCari(): array
    {
        return ['nama'];
    }

    /** Menu yang pernah tercatat di penjualan hanya boleh dinonaktifkan. */
    public function sudahDipakai(): bool
    {
        return DB::table('penjualan_detail')->where('menu_id', $this->id)->exists();
    }

    /**
     * Tanggal semua versi resep (Y-m-d), terbaru dulu.
     *
     * @return Collection<int, string>
     */
    public function versiResep(): Collection
    {
        return $this->resep()
            ->selectRaw('date(berlaku_sejak) as tanggal')
            ->distinct()
            ->orderByDesc('tanggal')
            ->pluck('tanggal')
            ->map(fn ($tanggal) => substr((string) $tanggal, 0, 10));
    }

    /** Tanggal versi resep yang berlaku pada tanggal tertentu (bawaan: hari ini), atau null. */
    public function tanggalVersiBerlaku($tanggal = null): ?string
    {
        $tanggal = $tanggal ?? now()->toDateString();

        return $this->versiResep()->first(fn (string $versi) => $versi <= $tanggal);
    }

    /** Baris resep satu versi, lengkap dengan bahan dan satuan dasarnya. */
    public function resepVersi(string $tanggal): Collection
    {
        return $this->resep()
            ->whereDate('berlaku_sejak', $tanggal)
            ->with('bahanBaku.satuanDasar')
            ->orderBy('id')
            ->get();
    }

    /** Baris resep versi yang berlaku di tanggal tertentu (bawaan: hari ini). */
    public function resepBerlaku($tanggal = null): Collection
    {
        $versi = $this->tanggalVersiBerlaku($tanggal);

        return $versi ? $this->resepVersi($versi) : collect();
    }
}
