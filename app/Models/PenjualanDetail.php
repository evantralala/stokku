<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Porsi terjual satu menu. takaran_resep_json adalah salinan resep yang berlaku di tanggal penjualan,
 * sehingga perubahan resep setelahnya tidak mengubah perhitungan pemakaian hari itu.
 */
class PenjualanDetail extends Model
{
    use SoftDeletes;

    protected $table = 'penjualan_detail';

    protected $fillable = [
        'penjualan_harian_id',
        'menu_id',
        'porsi',
        'takaran_resep_json',
    ];

    protected function casts(): array
    {
        return [
            'porsi' => 'integer',
            'takaran_resep_json' => 'array',
        ];
    }

    public function penjualanHarian(): BelongsTo
    {
        return $this->belongsTo(PenjualanHarian::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * Isi takaran_resep_json dari resep yang berlaku di tanggal tertentu.
     *
     * @return list<array{bahan_baku_id: int, nama: string, satuan: string, jumlah: float}>
     */
    public static function salinResep(Menu $menu, $tanggal = null): array
    {
        return $menu->resepBerlaku($tanggal)
            ->map(fn (Resep $r) => [
                'bahan_baku_id' => $r->bahan_baku_id,
                'nama'          => $r->bahanBaku->nama,
                'satuan'        => $r->bahanBaku->satuanDasar->nama,
                'jumlah'        => (float) $r->jumlah,
            ])
            ->values()
            ->all();
    }

    /**
     * Pemakaian bahan untuk porsi ini (satuan dasar).
     *
     * @return array<int, float> [bahan_baku_id => jumlah]
     */
    public function pemakaianBahan(): array
    {
        $hasil = [];

        foreach ($this->takaran_resep_json ?? [] as $baris) {
            $hasil[$baris['bahan_baku_id']] = round($this->porsi * (float) $baris['jumlah'], 3);
        }

        return $hasil;
    }
}
