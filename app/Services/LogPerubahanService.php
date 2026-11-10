<?php

namespace App\Services;

use App\Models\LogPerubahan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Menulis dan membaca log perubahan (siapa mengubah apa, kapan). */
class LogPerubahanService
{
    private const MAKS_RINGKASAN = 255;

    /**
     * @param  list<array{label: string, lama: ?string, baru: ?string}>  $rincian
     */
    public function catat(
        ?User $oleh,
        string $kategori,
        string $aksi,
        string $ringkasan,
        array $rincian = [],
        ?Model $objek = null,
    ): LogPerubahan {
        return LogPerubahan::create([
            'user_id'    => $oleh?->id,
            'kategori'   => $kategori,
            'aksi'       => $aksi,
            'ringkasan'  => Str::limit($ringkasan, self::MAKS_RINGKASAN - 3),
            'rincian'    => $rincian === [] ? null : $rincian,
            'objek_tipe' => $objek ? class_basename($objek) : null,
            'objek_id'   => $objek?->getKey(),
            'ip'         => request()->ip(),
        ]);
    }

    /**
     * Kolom yang nilainya berbeda. $sebelum dan $sesudah berisi teks tampilan
     * dengan kunci yang sama seperti $label (kunci kolom => label di layar).
     *
     * @return list<array{label: string, lama: ?string, baru: ?string}>
     */
    public static function rincian(array $sebelum, array $sesudah, array $label): array
    {
        $hasil = [];

        foreach ($label as $kunci => $teks) {
            $lama = $sebelum[$kunci] ?? null;
            $baru = $sesudah[$kunci] ?? null;

            if ((string) $lama !== (string) $baru) {
                $hasil[] = ['label' => $teks, 'lama' => $lama, 'baru' => $baru];
            }
        }

        return $hasil;
    }
}
