<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kategori menu, mis. Ramen, Side dish, Minuman. */
class KategoriMenu extends Model
{
    use DataMaster;

    protected $table = 'kategori_menu';

    protected $fillable = [
        'nama',
        'urutan',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    public function menu(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function sudahDipakai(): bool
    {
        return $this->menu()->exists();
    }

    /** Urutan untuk kategori baru: setelah yang terakhir. */
    public static function urutanBerikutnya(): int
    {
        return (int) static::query()->max('urutan') + 1;
    }
}
