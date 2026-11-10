<?php

namespace App\Support;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Menu;
use Illuminate\Support\Collection;

/**
 * Isi contoh templat Excel: beberapa baris data biasa (bukan baris abu-abu), kira-kira 3 per kategori,
 * supaya terlihat format isiannya dan file templat yang langsung diunggah pun bisa disimpan.
 *
 * Bila data di sistem sudah ada, contoh memakai data itu (bahan, menu, lokasi sungguhan) supaya cocok saat diunggah;
 * bila masih kosong, memakai daftar di bawah. Daftar contoh saling cocok: bahan memakai kategori dan satuan contoh,
 * resep memakai menu dan bahan contoh, sehingga templat yang diimpor berurutan (Satuan, Kategori, Bahan, Kategori Menu,
 * Menu, Resep, Stok Minimum) tetap tersimpan di database kosong.
 */
final class ContohTemplat
{
    public const PER_KATEGORI = 3;

    public const SATUAN = ['kg', 'gram', 'liter', 'ml', 'pcs', 'butir', 'lembar', 'botol'];

    /** [nama, kategori, satuan dasar, satuan beli, isi per satuan beli, toleransi %] */
    public const BAHAN = [
        ['Kol', 'Sayur & Buah', 'gram', 'kg', 1000, 5],
        ['Wortel', 'Sayur & Buah', 'gram', 'kg', 1000, 5],
        ['Daun Bawang', 'Sayur & Buah', 'gram', 'kg', 1000, 10],
        ['Beras Jepang', 'Bahan Pokok', 'gram', 'kg', 1000, 2],
        ['Mie Ramen', 'Bahan Pokok', 'gram', 'kg', 1000, 5],
        ['Tepung Panko', 'Bahan Pokok', 'gram', 'kg', 1000, 5],
        ['Kecap Asin Jepang', 'Bumbu & Saus', 'ml', 'liter', 1000, 5],
        ['Minyak Goreng', 'Bumbu & Saus', 'ml', 'liter', 1000, 5],
        ['Garam', 'Bumbu & Saus', 'gram', 'kg', 1000, 5],
    ];

    /** [nama, kategori menu] */
    public const MENU = [
        ['Shoyu Ramen', 'Ramen'], ['Miso Ramen', 'Ramen'], ['Tonkotsu Ramen', 'Ramen'],
        ['Gyudon', 'Donburi'], ['Katsudon', 'Donburi'], ['Oyakodon', 'Donburi'],
        ['Ocha Dingin', 'Minuman'], ['Ocha Panas', 'Minuman'], ['Air Mineral', 'Minuman'],
    ];

    /** [menu, kategori menu, bahan, takaran (satuan dasar)] */
    public const RESEP = [
        ['Shoyu Ramen', 'Ramen', 'Mie Ramen', 150],
        ['Shoyu Ramen', 'Ramen', 'Kecap Asin Jepang', 30],
        ['Shoyu Ramen', 'Ramen', 'Daun Bawang', 10],
        ['Gyudon', 'Donburi', 'Beras Jepang', 200],
        ['Gyudon', 'Donburi', 'Kecap Asin Jepang', 20],
        ['Gyudon', 'Donburi', 'Kol', 30],
        ['Katsudon', 'Donburi', 'Beras Jepang', 200],
        ['Katsudon', 'Donburi', 'Tepung Panko', 40],
        ['Katsudon', 'Donburi', 'Minyak Goreng', 50],
    ];

    /** @return list<string> */
    public static function kategoriBahan(): array
    {
        return array_values(array_unique(array_column(self::BAHAN, 1)));
    }

    /** @return list<string> */
    public static function kategoriMenu(): array
    {
        return array_values(array_unique(array_column(self::MENU, 1)));
    }

    /**
     * Bahan sungguhan (paling banyak PER_KATEGORI per kategori, bahan aktif), atau bahan contoh bila belum ada.
     *
     * @return Collection<int, array{nama: string, kategori: string, satuan_dasar: string, satuan_beli: string, isi: float}>
     */
    public static function bahan(): Collection
    {
        $ada = BahanBaku::query()->aktif()->with(['kategori:id,nama,urutan', 'satuanDasar:id,nama', 'satuanBeli:id,nama'])->get()
            ->sortBy(fn (BahanBaku $b) => [$b->kategori->urutan, $b->kategori->nama, $b->nama])
            ->groupBy(fn (BahanBaku $b) => $b->kategori_id)
            ->flatMap(fn (Collection $k) => $k->take(self::PER_KATEGORI))
            ->map(fn (BahanBaku $b) => [
                'nama' => $b->nama, 'kategori' => $b->kategori->nama, 'satuan_dasar' => $b->satuanDasar->nama,
                'satuan_beli' => $b->satuanBeli->nama, 'isi' => (float) $b->isi_per_satuan_beli,
            ])
            ->values();

        return $ada->isNotEmpty() ? $ada : collect(self::BAHAN)->map(fn (array $b) => [
            'nama' => $b[0], 'kategori' => $b[1], 'satuan_dasar' => $b[2], 'satuan_beli' => $b[3], 'isi' => (float) $b[4],
        ]);
    }

    /**
     * Menu sungguhan (paling banyak PER_KATEGORI per kategori menu, menu aktif), atau menu contoh bila belum ada.
     *
     * @return Collection<int, array{nama: string, kategori: string}>
     */
    public static function menu(): Collection
    {
        $ada = Menu::query()->aktif()->with('kategoriMenu:id,nama,urutan')->get()
            ->sortBy(fn (Menu $m) => [$m->kategoriMenu->urutan, $m->kategoriMenu->nama, $m->nama])
            ->groupBy(fn (Menu $m) => $m->kategori_menu_id)
            ->flatMap(fn (Collection $k) => $k->take(self::PER_KATEGORI))
            ->map(fn (Menu $m) => ['nama' => $m->nama, 'kategori' => $m->kategoriMenu->nama])
            ->values();

        return $ada->isNotEmpty() ? $ada : collect(self::MENU)->map(fn (array $m) => ['nama' => $m[0], 'kategori' => $m[1]]);
    }

    /** Kode lokasi sungguhan (Gudang Pusat dulu), atau GP bila belum ada lokasi. */
    public static function kodeLokasi(?string $jenis = null): string
    {
        return Cabang::query()->aktif()
            ->when($jenis, fn ($q) => $q->where('jenis', $jenis))
            ->orderByDesc('jenis')->orderBy('nama')->value('kode')
            ?? ($jenis === Cabang::JENIS_CABANG ? 'JTI' : 'GP');
    }

    /** Angka contoh yang wajar per baris, mis. 12, 5, 8, ... */
    public static function angka(int $i, array $pola = [12, 5, 8, 20, 3, 15, 6, 10, 4]): int
    {
        return $pola[$i % count($pola)];
    }
}
