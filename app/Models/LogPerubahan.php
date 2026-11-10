<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan siapa mengubah apa dan kapan. Ditulis lewat LogPerubahanService,
 * tidak pernah diubah atau dihapus.
 */
class LogPerubahan extends Model
{
    public const KATEGORI_SISTEM = 'sistem';
    public const KATEGORI_AKUN = 'akun';
    public const KATEGORI_PENGGUNA = 'pengguna';
    public const KATEGORI_PENGATURAN = 'pengaturan';
    public const KATEGORI_DATA = 'data';

    /** Nama kategori yang ditampilkan di layar. */
    public const LABEL_KATEGORI = [
        self::KATEGORI_SISTEM     => 'Sistem',
        self::KATEGORI_AKUN       => 'Akun',
        self::KATEGORI_PENGGUNA   => 'Pengguna',
        self::KATEGORI_PENGATURAN => 'Pengaturan',
        self::KATEGORI_DATA       => 'Data Master',
    ];

    public const UPDATED_AT = null;

    protected $table = 'log_perubahan';

    protected $fillable = [
        'user_id',
        'kategori',
        'aksi',
        'ringkasan',
        'rincian',
        'objek_tipe',
        'objek_id',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'rincian'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function oleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Rincian siap tampil: list [label, lama, baru]. Rincian berbentuk lain (mis. ['dari' => 'GP', 'ke' => 'JTI']
     * atau ['porsi' => ['Gyudon' => '2 → 3']]) diubah menjadi baris label + nilai, jadi halaman log tidak pernah galat.
     *
     * @return list<array{label: string, lama: ?string, baru: ?string}>
     */
    protected function barisRincian(): Attribute
    {
        return Attribute::get(function () {
            $baris = [];

            foreach ((array) $this->rincian as $kunci => $isi) {
                if (is_array($isi) && array_key_exists('label', $isi)) {
                    $baris[] = ['label' => (string) $isi['label'], 'lama' => self::teks($isi['lama'] ?? null), 'baru' => self::teks($isi['baru'] ?? null)];
                } elseif (is_array($isi) && ! array_is_list($isi)) {
                    foreach ($isi as $sub => $nilai) {
                        $baris[] = ['label' => (string) $sub, 'lama' => null, 'baru' => self::teks($nilai)];
                    }
                } else {
                    $baris[] = ['label' => ucfirst(str_replace('_', ' ', (string) $kunci)), 'lama' => null, 'baru' => self::teks($isi)];
                }
            }

            return $baris;
        });
    }

    private static function teks(mixed $nilai): ?string
    {
        return match (true) {
            $nilai === null, $nilai === [] => null,
            is_bool($nilai)                => $nilai ? 'Ya' : 'Tidak',
            is_array($nilai)               => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $nilai)),
            default                        => (string) $nilai,
        };
    }

    /** $log->label_kategori -> "Pengaturan" */
    protected function labelKategori(): Attribute
    {
        return Attribute::get(fn () => self::LABEL_KATEGORI[$this->kategori] ?? $this->kategori);
    }
}
