<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Stok minimum bahan baku per lokasi, disimpan dalam satuan dasar.
 * Bahan yang tidak punya baris dianggap minimumnya 0.
 */
class BahanBakuMinimum extends Model
{
    protected $table = 'bahan_baku_minimum';

    protected $fillable = [
        'bahan_baku_id',
        'cabang_id',
        'minimum',
    ];

    protected function casts(): array
    {
        return [
            'minimum' => 'decimal:3',
        ];
    }

    public function bahanBaku(): BelongsTo
    {
        return $this->belongsTo(BahanBaku::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    /**
     * Simpan minimum satu lokasi dari isian dalam satuan beli.
     * Nilai kosong atau 0 menghapus baris (berarti tidak ada batas minimum).
     *
     * @param  array<int|string, string|float|null>  $nilaiSatuanBeli  [bahan_baku_id => jumlah dalam satuan beli]
     * @return int jumlah bahan yang diproses
     */
    public static function simpanUntukLokasi(int $lokasiId, array $nilaiSatuanBeli): int
    {
        $bahan = BahanBaku::query()
            ->aktif()
            ->whereKey(array_keys($nilaiSatuanBeli))
            ->get(['id', 'isi_per_satuan_beli']);

        DB::transaction(function () use ($bahan, $lokasiId, $nilaiSatuanBeli) {
            foreach ($bahan as $b) {
                $nilai = (float) ($nilaiSatuanBeli[$b->id] ?? 0);

                if ($nilai <= 0) {
                    static::where('cabang_id', $lokasiId)->where('bahan_baku_id', $b->id)->delete();

                    continue;
                }

                static::updateOrCreate(
                    ['cabang_id' => $lokasiId, 'bahan_baku_id' => $b->id],
                    ['minimum' => $b->keSatuanDasar($nilai)]
                );
            }
        });

        return $bahan->count();
    }

    /** Ganti semua minimum di lokasi tujuan dengan milik lokasi asal. */
    public static function salin(int $dariLokasiId, int $keLokasiId): int
    {
        return DB::transaction(function () use ($dariLokasiId, $keLokasiId) {
            static::where('cabang_id', $keLokasiId)->delete();

            $sumber = static::where('cabang_id', $dariLokasiId)->get(['bahan_baku_id', 'minimum']);

            foreach ($sumber as $baris) {
                static::create([
                    'cabang_id'     => $keLokasiId,
                    'bahan_baku_id' => $baris->bahan_baku_id,
                    'minimum'       => $baris->minimum,
                ]);
            }

            return $sumber->count();
        });
    }
}
