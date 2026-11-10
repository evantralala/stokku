<?php

namespace App\Services;

use App\Models\LogPerubahan;
use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Menyimpan nilai pengaturan sistem dan mencatat perubahannya. */
class PengaturanService
{
    public function __construct(private LogPerubahanService $log) {}

    /**
     * Simpan nilai yang berubah saja.
     *
     * @param  Collection<string, Pengaturan>  $daftar  pengaturan per kunci
     * @param  array<string, mixed>  $nilaiBaru  isian yang sudah lolos validasi
     * @return int jumlah pengaturan yang berubah
     */
    public function simpan(Collection $daftar, array $nilaiBaru, User $oleh): int
    {
        return DB::transaction(function () use ($daftar, $nilaiBaru, $oleh) {
            $rincian = [];

            foreach ($daftar as $kunci => $pengaturan) {
                if (! array_key_exists($kunci, $nilaiBaru)) {
                    continue;
                }

                $lama = (string) $pengaturan->nilai;
                $pengaturan->nilai = $pengaturan->rapikanNilai($nilaiBaru[$kunci]);

                if (! $pengaturan->isDirty('nilai')) {
                    continue;
                }

                $pengaturan->save();

                $rincian[] = [
                    'label' => $pengaturan->label ?? $kunci,
                    'lama'  => $this->teks($pengaturan, $lama),
                    'baru'  => $this->teks($pengaturan, $pengaturan->nilai),
                ];
            }

            if ($rincian !== []) {
                $this->log->catat(
                    $oleh,
                    LogPerubahan::KATEGORI_PENGATURAN,
                    'pengaturan.ubah',
                    count($rincian) === 1 ? "Pengaturan diubah: {$rincian[0]['label']}" : count($rincian).' pengaturan diubah',
                    $rincian,
                );
            }

            return count($rincian);
        });
    }

    private function teks(Pengaturan $pengaturan, string $nilai): string
    {
        return trim($nilai.' '.($pengaturan->satuan ?? ''));
    }
}
