<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu sakelar sistem berbentuk kunci-nilai. Hanya diubah lewat KontrolSistemService. */
class KontrolSistem extends Model
{
    protected $table = 'kontrol_sistem';

    protected $fillable = [
        'kunci',
        'nilai',
        'diubah_oleh',
    ];

    public function pengubah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diubah_oleh');
    }
}
