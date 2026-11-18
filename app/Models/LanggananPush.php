<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Langganan notifikasi HP (Web Push) satu browser/perangkat milik satu pengguna. Dikelola App\Services\PushService. */
class LanggananPush extends Model
{
    protected $table = 'langganan_push';

    protected $fillable = [
        'user_id',
        'endpoint_hash',
        'endpoint',
        'p256dh',
        'auth',
        'perangkat',
        'terakhir_dikirim',
    ];

    protected function casts(): array
    {
        return [
            'terakhir_dikirim' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
