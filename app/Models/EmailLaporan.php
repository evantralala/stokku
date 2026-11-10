<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengaturan email laporan harian satu akun. alamat = daftar email tujuan, laporan = kunci laporan yang dicentang
 * (lihat App\Support\DaftarLaporanEmail). terakhir_dikirim = tanggal laporan terakhir yang terkirim terjadwal, supaya
 * laporan hari yang sama tidak terkirim dua kali.
 */
class EmailLaporan extends Model
{
    protected $table = 'email_laporan';

    protected $fillable = [
        'user_id',
        'aktif',
        'alamat',
        'laporan',
        'terakhir_dikirim',
    ];

    protected function casts(): array
    {
        return [
            'aktif'            => 'boolean',
            'alamat'           => 'array',
            'laporan'          => 'array',
            'terakhir_dikirim' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
