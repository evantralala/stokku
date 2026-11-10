<?php

namespace App\Models;

use App\Models\Concerns\DataMaster;
use App\Models\Concerns\PunyaFoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class BahanBaku extends Model
{
    use DataMaster;
    use PunyaFoto;

    /** Tabel transaksi dan resep yang merujuk ke bahan baku. Stok minimum tidak termasuk (ikut terhapus). */
    private const RUJUKAN = [
        'stok_saldo',
        'stok_mutasi',
        'resep',
        'permintaan_detail',
        'kiriman_detail',
        'barang_masuk_detail',
        'barang_keluar_detail',
        'opname_detail',
    ];

    protected $table = 'bahan_baku';

    protected $fillable = [
        'nama',
        'nama_lain',
        'foto',
        'kategori_id',
        'satuan_dasar_id',
        'satuan_beli_id',
        'isi_per_satuan_beli',
        'toleransi_persen',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'isi_per_satuan_beli' => 'decimal:3',
            'toleransi_persen' => 'decimal:2',
            'aktif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Stok minimum bukan riwayat transaksi, jadi ikut dihapus bersama bahan baku yang belum dipakai.
        static::deleting(fn (BahanBaku $bahan) => $bahan->minimum()->delete());
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(Kategori::class);
    }

    /** Satuan penyimpanan stok, misalnya gram. */
    public function satuanDasar(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'satuan_dasar_id');
    }

    /** Satuan saat membeli, misalnya kg. */
    public function satuanBeli(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'satuan_beli_id');
    }

    public function minimum(): HasMany
    {
        return $this->hasMany(BahanBakuMinimum::class);
    }

    public function resep(): HasMany
    {
        return $this->hasMany(Resep::class);
    }

    public function kolomCari(): array
    {
        return ['nama'];
    }

    /** @return list<string> nama lain bahan (kolom nama_lain dipisah koma) */
    public function daftarNamaLain(): array
    {
        return self::pecahNamaLain($this->nama_lain);
    }

    /** "telor, Egg ,telor" -> ["telor", "Egg"] (tanpa kosong dan tanpa ganda, huruf besar-kecil diabaikan). */
    public static function pecahNamaLain(?string $teks): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $teks))
            ->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n)))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->values()
            ->all();
    }

    /** Tambahkan satu nama lain (bila belum ada dan bukan nama bahan itu sendiri). */
    public function tambahNamaLain(string $nama): bool
    {
        $nama = trim(preg_replace('/\s+/', ' ', $nama));
        $ada = array_map('mb_strtolower', [$this->nama, ...$this->daftarNamaLain()]);

        if ($nama === '' || in_array(mb_strtolower($nama), $ada, true)) {
            return false;
        }

        $baru = implode(', ', [...$this->daftarNamaLain(), $nama]);

        if (mb_strlen($baru) > 500) {
            return false;
        }

        $this->forceFill(['nama_lain' => $baru])->saveQuietly();

        return true;
    }

    public function sudahDipakai(): bool
    {
        foreach (self::RUJUKAN as $tabel) {
            if (DB::table($tabel)->where('bahan_baku_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** 80 (kg) -> 80000 (gram) */
    public function keSatuanDasar($qtyBeli): float
    {
        return round((float) $qtyBeli * (float) $this->isi_per_satuan_beli, 3);
    }

    /** 80000 (gram) -> 80 (kg) */
    public function keSatuanBeli($qtyDasar): float
    {
        return round((float) $qtyDasar / (float) $this->isi_per_satuan_beli, 3);
    }
}
