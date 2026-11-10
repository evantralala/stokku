<?php

namespace App\Services;

use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\Resep;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan versi resep.
 *
 * Satu versi = semua baris resep satu menu dengan tanggal berlaku_sejak yang sama.
 * Versi yang tanggalnya sudah lewat tidak pernah diubah atau dihapus, supaya
 * perhitungan pemakaian bahan untuk hari-hari sebelumnya tetap sama.
 */
class ResepService
{
    public function __construct(private LogPerubahanService $log)
    {
    }

    /**
     * Simpan (atau timpa) versi resep untuk tanggal hari ini atau setelahnya.
     *
     * @param  array<int, array{bahan_baku_id: int|string, jumlah: int|float|string}>  $baris
     * @return int jumlah baris resep yang tersimpan
     */
    public function simpanVersi(Menu $menu, string $berlakuSejak, array $baris, ?User $oleh = null): int
    {
        $this->pastikanBelumLewat($berlakuSejak, 'diubah');

        return DB::transaction(function () use ($menu, $berlakuSejak, $baris, $oleh) {
            Resep::where('menu_id', $menu->id)->whereDate('berlaku_sejak', $berlakuSejak)->delete();

            foreach ($baris as $b) {
                Resep::create([
                    'menu_id'       => $menu->id,
                    'bahan_baku_id' => (int) $b['bahan_baku_id'],
                    'jumlah'        => $b['jumlah'],
                    'berlaku_sejak' => $berlakuSejak,
                ]);
            }

            if ($oleh) {
                $this->log->catat($oleh, LogPerubahan::KATEGORI_DATA, 'resep.simpan',
                    "Resep {$menu->nama} berlaku ".Carbon::parse($berlakuSejak)->translatedFormat('j M Y').': '.count($baris).' bahan', [], $menu);
            }

            return count($baris);
        });
    }

    /** Hapus versi resep yang dijadwalkan untuk tanggal setelah hari ini. */
    public function hapusJadwal(Menu $menu, string $tanggal, ?User $oleh = null): int
    {
        if ($tanggal <= now()->toDateString()) {
            throw new DomainException('Hanya resep yang dijadwalkan untuk tanggal setelah hari ini yang bisa dihapus.');
        }

        return DB::transaction(function () use ($menu, $tanggal, $oleh) {
            $jumlah = Resep::where('menu_id', $menu->id)->whereDate('berlaku_sejak', $tanggal)->delete();

            if ($oleh && $jumlah > 0) {
                $this->log->catat($oleh, LogPerubahan::KATEGORI_DATA, 'resep.hapus',
                    "Jadwal resep {$menu->nama} tanggal ".Carbon::parse($tanggal)->translatedFormat('j M Y').' dihapus', [], $menu);
            }

            return $jumlah;
        });
    }

    private function pastikanBelumLewat(string $tanggal, string $aksi): void
    {
        if ($tanggal < now()->toDateString()) {
            throw new DomainException("Resep yang tanggal berlakunya sudah lewat tidak bisa {$aksi}.");
        }
    }
}
