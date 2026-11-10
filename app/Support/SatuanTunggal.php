<?php

namespace App\Support;

use App\Models\Satuan;

/**
 * Bahan baku cukup punya satu satuan (mis. kg, liter, pcs, botol). Untuk kg dan liter, resep ditulis dalam satuan
 * kecilnya (gram dan ml) supaya takaran per porsi tidak berupa pecahan kecil. Di database tetap tersimpan sebagai
 * satuan dasar (resep), satuan beli (stok), dan isi; kelas ini yang menurunkannya dari satu satuan.
 */
class SatuanTunggal
{
    /** Satuan => [satuan resep, isi 1 satuan dalam satuan resep] */
    public const TURUNAN = [
        'kg'       => ['gram', 1000],
        'kilogram' => ['gram', 1000],
        'liter'    => ['ml', 1000],
        'ltr'      => ['ml', 1000],
        'l'        => ['ml', 1000],
    ];

    /** @return array{0: string, 1: float} [nama satuan resep, isi] */
    public static function turunan(string $satuan): array
    {
        [$resep, $isi] = self::TURUNAN[mb_strtolower(trim($satuan))] ?? [$satuan, 1];

        return [$resep, (float) $isi];
    }

    /** Satuan tunggal dari satuan resep lama: gram -> kg, ml -> liter, lainnya tetap. */
    public static function dariSatuanResep(string $satuanResep): string
    {
        return match (mb_strtolower(trim($satuanResep))) {
            'gram', 'g', 'gr' => 'kg',
            'ml'              => 'liter',
            default           => $satuanResep,
        };
    }

    /**
     * Id satuan dasar, satuan beli, dan isi untuk satu satuan. Satuan resep (gram/ml) dibuat bila belum ada.
     *
     * @return array{satuan_dasar_id: int, satuan_beli_id: int, isi_per_satuan_beli: float}
     */
    public static function untuk(Satuan $satuan): array
    {
        [$namaResep, $isi] = self::turunan($satuan->nama);

        if ($isi === 1.0) {
            return ['satuan_dasar_id' => $satuan->id, 'satuan_beli_id' => $satuan->id, 'isi_per_satuan_beli' => 1.0];
        }

        $resep = Satuan::query()->whereRaw('lower(nama) = ?', [mb_strtolower($namaResep)])->first()
            ?? Satuan::create(['nama' => $namaResep, 'aktif' => true]);

        return ['satuan_dasar_id' => $resep->id, 'satuan_beli_id' => $satuan->id, 'isi_per_satuan_beli' => $isi];
    }
}
