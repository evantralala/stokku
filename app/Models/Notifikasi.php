<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pemberitahuan untuk satu pengguna. Dibuat lewat App\Services\NotifikasiService. */
class Notifikasi extends Model
{
    use SoftDeletes;

    public const MAKS_JUDUL = 100;
    public const MAKS_KETERANGAN = 255;

    protected $table = 'notifikasi';

    protected $fillable = [
        'user_id',
        'judul',
        'keterangan',
        'tautan',
        'dibaca_pada',
    ];

    protected function casts(): array
    {
        return [
            'dibaca_pada' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeMilik(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    public function scopeBelumDibaca(Builder $query): void
    {
        $query->whereNull('dibaca_pada');
    }

    public function sudahDibaca(): bool
    {
        return $this->dibaca_pada !== null;
    }

    public function tandaiDibaca(): void
    {
        if ($this->dibaca_pada === null) {
            $this->update(['dibaca_pada' => now()]);
        }
    }
}
