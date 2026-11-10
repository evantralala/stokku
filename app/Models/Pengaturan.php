<?php

namespace App\Models;

use App\Support\Angka;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan sistem berbentuk kunci-nilai. Nilai selalu disimpan sebagai teks;
 * tipe, satuan, dan batas nilai dipakai halaman Pengaturan untuk menampilkan dan memvalidasi isian.
 */
class Pengaturan extends Model
{
    public const TIPE_BILANGAN = 'bilangan';
    public const TIPE_PERSEN = 'persen';
    public const TIPE_JAM = 'jam';
    public const TIPE_TEKS = 'teks';

    private const MAKS_TEKS = 255;

    protected $table = 'pengaturan';

    protected $fillable = [
        'kunci',
        'label',
        'nilai',
        'tipe',
        'satuan',
        'nilai_min',
        'nilai_maks',
        'keterangan',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'nilai_min' => 'decimal:2',
            'nilai_maks' => 'decimal:2',
            'urutan' => 'integer',
        ];
    }

    public static function ambil(string $kunci, $default = null)
    {
        return static::where('kunci', $kunci)->value('nilai') ?? $default;
    }

    public static function simpan(string $kunci, $nilai): void
    {
        static::where('kunci', $kunci)->update(['nilai' => (string) $nilai]);
    }

    /** Aturan validasi nilai sesuai tipe dan batasnya. */
    public function aturanValidasi(): array
    {
        $batas = array_filter([
            $this->nilai_min !== null ? 'min:'.(float) $this->nilai_min : null,
            $this->nilai_maks !== null ? 'max:'.(float) $this->nilai_maks : null,
        ]);

        return match ($this->tipe) {
            self::TIPE_BILANGAN => ['required', 'integer', ...$batas],
            self::TIPE_PERSEN   => ['required', 'numeric', ...$batas],
            self::TIPE_JAM      => ['required', 'date_format:H:i'],
            default             => ['required', 'string', 'max:'.self::MAKS_TEKS],
        };
    }

    /** Ubah isian (sudah lolos validasi) menjadi teks yang disimpan. */
    public function rapikanNilai(mixed $nilai): string
    {
        return match ($this->tipe) {
            self::TIPE_BILANGAN => (string) (int) $nilai,
            self::TIPE_PERSEN   => str_replace(',', '.', Angka::isian($nilai, 2)),
            default             => trim((string) $nilai),
        };
    }

    /** Teks rentang nilai yang diizinkan, mis. "1–168 jam". Kosong bila tanpa batas. */
    public function teksRentang(): string
    {
        if ($this->nilai_min === null && $this->nilai_maks === null) {
            return '';
        }

        $rentang = Angka::tampil($this->nilai_min ?? 0, 2).'–'.Angka::tampil($this->nilai_maks, 2);

        return trim($rentang.' '.$this->satuan);
    }
}
