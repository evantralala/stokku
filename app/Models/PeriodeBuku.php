<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu periode buku yang sudah ditutup lewat Pengaturan > Periode Buku.
 * Transaksinya diarsipkan ke berkas Excel dan cadangan database (folder ARSIP di disk local), lalu dihapus dari sistem.
 * Stok terakhir menjadi stok awal periode berikutnya (mutasi jenis stok_awal dengan dokumen_tipe DOKUMEN_TIPE).
 * penjualan_terakhir: [cabang_id => tanggal penjualan terakhir yang ikut diarsipkan], supaya tanggal itu tidak dicatat dua kali.
 */
class PeriodeBuku extends Model
{
    public const DOKUMEN_TIPE = 'periode_buku';

    public const DISK = 'local';
    public const FOLDER = 'arsip-periode';

    protected $table = 'periode_buku';

    protected $fillable = [
        'nama',
        'mulai',
        'selesai',
        'berkas_excel',
        'berkas_cadangan',
        'ringkasan',
        'penjualan_terakhir',
        'ditutup_oleh',
    ];

    protected function casts(): array
    {
        return [
            'mulai'              => 'datetime',
            'selesai'            => 'datetime',
            'ringkasan'          => 'array',
            'penjualan_terakhir' => 'array',
        ];
    }

    public function penutup(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditutup_oleh');
    }

    /** Dipakai TautanDokumen untuk kolom dokumen di kartu stok. */
    public function getNomorAttribute(): string
    {
        return 'Stok awal '.$this->nama;
    }

    public function rentang(): string
    {
        $selesai = $this->selesai->translatedFormat('j M Y');

        return $this->mulai ? $this->mulai->translatedFormat('j M Y').' – '.$selesai : 'Sampai '.$selesai;
    }
}
