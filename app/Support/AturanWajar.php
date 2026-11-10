<?php

namespace App\Support;

use App\Models\BarangMasuk;
use App\Models\Permintaan;
use App\Services\PeringatanDiniService;

/**
 * Aturan "wajar" untuk tombol "Setujui semua yang wajar" di Persetujuan.
 * Sebuah dokumen wajar bila jenisnya boleh disetujui sekaligus, tidak ada peringatan dini di lokasinya,
 * dan setiap jumlahnya masih dalam toleransi bahan (toleransi_persen):
 *   - permintaan: jumlah diminta <= kebutuhan cabang (seperti usulan otomatis) + toleransi, dan stok Gudang Pusat cukup;
 *   - barang masuk: jumlah <= jumlah terbesar dari beberapa barang masuk disetujui terakhir untuk bahan itu + toleransi.
 * Dokumen wajar tetap ditampilkan dalam ringkasan sebelum disetujui. Ubah konstanta di sini bila keputusan berubah.
 */
final class AturanWajar
{
    /** Jenis dokumen yang boleh disetujui sekaligus. Barang rusak dan stock opname selalu dicek satu per satu. */
    public const JENIS_MASSAL = [
        Permintaan::DOKUMEN_TIPE,
        BarangMasuk::DOKUMEN_TIPE,
    ];

    /**
     * Dokumen di lokasi yang punya peringatan dini dengan tingkat ini tidak dianggap wajar
     * (termasuk "penjualan belum dicatat"). Kosongkan untuk tidak memeriksa peringatan dini.
     */
    public const TINGKAT_PERINGATAN = [
        PeringatanDiniService::TINGKAT_TINGGI,
        PeringatanDiniService::TINGKAT_SEDANG,
    ];

    /** Jumlah barang masuk disetujui terakhir (per bahan per lokasi) yang dijadikan pembanding. */
    public const RIWAYAT_BARANG_MASUK = 5;

    /** Pemisah jenis dan id dalam kunci dokumen, mis. "permintaan:12". */
    public const PEMISAH_KUNCI = ':';

    public static function bolehMassal(string $jenis): bool
    {
        return in_array($jenis, self::JENIS_MASSAL, true);
    }

    public static function kunci(string $jenis, int $id): string
    {
        return $jenis.self::PEMISAH_KUNCI.$id;
    }
}
