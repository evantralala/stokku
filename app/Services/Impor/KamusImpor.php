<?php

namespace App\Services\Impor;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Satuan;
use App\Support\PencocokNama;

/**
 * Daftar nama yang ada di sistem untuk pembersih impor (bahan, menu, lokasi, kategori, kategori menu, satuan),
 * dimuat sekali per pemeriksaan file.
 */
final class KamusImpor
{
    public const BAHAN = 'bahan';
    public const MENU = 'menu';
    public const LOKASI = 'lokasi';
    public const KATEGORI = 'kategori';
    public const KATEGORI_MENU = 'kategori_menu';
    public const SATUAN = 'satuan';

    public const LABEL = [
        self::BAHAN         => 'Bahan',
        self::MENU          => 'Menu',
        self::LOKASI        => 'Lokasi',
        self::KATEGORI      => 'Kategori',
        self::KATEGORI_MENU => 'Kategori menu',
        self::SATUAN        => 'Satuan',
    ];

    /** @var array<string, PencocokNama> */
    private array $pencocok = [];

    /** @var array<string, string>|null [nama lain huruf kecil => nama bahan] */
    private ?array $namaLain = null;

    /** @var array<string, list<string>> */
    private array $nama = [];

    /** @var array<string, string>|null [kode huruf kecil => kode] */
    private ?array $kodeLokasi = null;

    /** @return list<string> */
    public function nama(string $jenis): array
    {
        return $this->nama[$jenis] ??= match ($jenis) {
            self::BAHAN         => BahanBaku::query()->tampil()->orderBy('nama')->pluck('nama')->all(),
            self::MENU          => Menu::query()->tampil()->orderBy('nama')->pluck('nama')->all(),
            self::LOKASI        => Cabang::query()->tampil()->orderBy('nama')->pluck('nama')->all(),
            self::KATEGORI      => Kategori::query()->tampil()->orderBy('nama')->pluck('nama')->all(),
            self::KATEGORI_MENU => KategoriMenu::query()->tampil()->orderBy('nama')->pluck('nama')->all(),
            self::SATUAN        => Satuan::query()->tampil()->orderBy('nama')->pluck('nama')->all(),
        };
    }

    /** Nama bahan dari nama lainnya (kolom Nama Lain, huruf besar-kecil diabaikan), atau null. */
    public function bahanDariNamaLain(string $teks): ?string
    {
        $this->namaLain ??= BahanBaku::query()->tampil()->whereNotNull('nama_lain')->get(['nama', 'nama_lain'])
            ->flatMap(fn (BahanBaku $b) => collect($b->daftarNamaLain())->mapWithKeys(fn ($lain) => [mb_strtolower($lain) => $b->nama]))
            ->all();

        return $this->namaLain[mb_strtolower(trim(preg_replace('/\s+/', ' ', $teks)))] ?? null;
    }

    public function pencocok(string $jenis): PencocokNama
    {
        if (! isset($this->pencocok[$jenis])) {
            $nama = $jenis === self::LOKASI
                ? Cabang::query()->tampil()->orderBy('nama')->pluck('nama', 'kode')->all()
                : $this->nama($jenis);

            $this->pencocok[$jenis] = new PencocokNama($nama);
        }

        return $this->pencocok[$jenis];
    }

    /** Kode lokasi yang ditulis persis (huruf besar-kecil diabaikan), mis. "gp" -> "GP"; null bila bukan kode. */
    public function kodeLokasi(string $teks): ?string
    {
        $this->kodeLokasi ??= Cabang::query()->tampil()->pluck('kode')->mapWithKeys(fn ($k) => [mb_strtolower($k) => $k])->all();

        return $this->kodeLokasi[mb_strtolower($teks)] ?? null;
    }
}
