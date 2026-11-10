<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Mencocokkan nama ketikan dengan daftar nama yang ada, tanpa AI:
 * - "pas": sama setelah huruf besar-kecil, spasi, dan tanda baca diabaikan ("beras  JEPANG" = "Beras Jepang");
 * - "tebak": mirip (salah ketik "Beras Jepnag", singkatan "Brs Jepang", atau nama terpotong "Air Mineral"),
 *   hanya disarankan, tidak pernah dipakai tanpa persetujuan pengguna;
 * - "tidak": tidak ada yang cukup mirip.
 */
final class PencocokNama
{
    public const PAS = 'pas';
    public const TEBAK = 'tebak';
    public const TIDAK = 'tidak';

    /** Skor minimal untuk disarankan (0..1). Nama pendek butuh skor lebih tinggi. */
    private const AMBANG = 0.78;
    private const AMBANG_PENDEK = 0.86;
    private const PANJANG_PENDEK = 6;

    /** @var array<string, string> [kunci rapat => nama asli] */
    private array $rapat = [];

    /** @var list<array{nama: string, kunci: string, token: list<string>}> */
    private array $daftar = [];

    /** @param  iterable<string>  $nama  nama yang ada; boleh juga [alias => nama asli] untuk kode (mis. "GP" => "Gudang Pusat") */
    public function __construct(iterable $nama)
    {
        foreach ($nama as $alias => $asli) {
            foreach (array_unique([is_string($alias) ? $alias : $asli, $asli]) as $teks) {
                $kunci = self::kunci($teks);

                if ($kunci === '') {
                    continue;
                }

                $this->rapat[str_replace(' ', '', $kunci)] ??= $asli;
                $this->daftar[] = ['nama' => $asli, 'kunci' => $kunci, 'token' => explode(' ', $kunci)];
            }
        }
    }

    /** @return array{jenis: string, nama: ?string, skor: float} */
    public function cari(?string $teks): array
    {
        $kunci = self::kunci((string) $teks);

        if ($kunci === '') {
            return ['jenis' => self::TIDAK, 'nama' => null, 'skor' => 0.0];
        }

        $rapat = str_replace(' ', '', $kunci);

        if (isset($this->rapat[$rapat])) {
            return ['jenis' => self::PAS, 'nama' => $this->rapat[$rapat], 'skor' => 1.0];
        }

        $token = explode(' ', $kunci);
        $skor = [];

        foreach ($this->daftar as $calon) {
            $nilai = max(
                self::mirip($rapat, str_replace(' ', '', $calon['kunci'])),
                self::miripToken($token, $calon['token']),
            );
            $skor[$calon['nama']] = max($skor[$calon['nama']] ?? 0, $nilai);
        }

        arsort($skor);
        $terbaik = array_key_first($skor);

        if ($terbaik === null) {
            return ['jenis' => self::TIDAK, 'nama' => null, 'skor' => 0.0];
        }

        $nilai = $skor[$terbaik];
        $ambang = mb_strlen($rapat) < self::PANJANG_PENDEK ? self::AMBANG_PENDEK : self::AMBANG;
        $kedua = array_values($skor)[1] ?? 0;

        if ($nilai < $ambang || $nilai - $kedua < 0.04) {
            return ['jenis' => self::TIDAK, 'nama' => null, 'skor' => $nilai];
        }

        return ['jenis' => self::TEBAK, 'nama' => $terbaik, 'skor' => round($nilai, 3)];
    }

    /** Huruf kecil, tanpa aksen dan tanda baca, spasi tunggal. */
    public static function kunci(string $teks): string
    {
        $teks = mb_strtolower(Str::ascii($teks));
        $teks = str_replace('&', ' dan ', $teks);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $teks)));
    }

    /** Kemiripan 0..1 berdasarkan jarak Damerau (salah ketik, huruf tertukar, kurang/lebih huruf). */
    private static function mirip(string $a, string $b): float
    {
        $panjang = max(strlen($a), strlen($b));

        return $panjang === 0 ? 0.0 : 1 - self::jarak($a, $b) / $panjang;
    }

    /**
     * Kemiripan per kata: setiap kata ketikan cocok dengan kata calon secara berurutan, boleh singkatan
     * ("brs" untuk "beras": huruf awal sama dan hurufnya berurutan) atau salah ketik kecil.
     * Kata calon yang tersisa (nama terpotong) mengurangi skor sedikit.
     *
     * @param  list<string>  $ketik
     * @param  list<string>  $calon
     */
    private static function miripToken(array $ketik, array $calon): float
    {
        if (count($ketik) > count($calon)) {
            return 0.0;
        }

        $posisi = 0;
        $total = 0.0;

        foreach ($ketik as $kata) {
            $cocok = null;

            for ($j = $posisi; $j < count($calon); $j++) {
                $nilai = self::miripKata($kata, $calon[$j]);

                if ($nilai >= 0.75) {
                    $cocok = [$j, $nilai];
                    break;
                }
            }

            if ($cocok === null) {
                return 0.0;
            }

            [$posisi, $nilai] = $cocok;
            $posisi++;
            $total += $nilai;
        }

        $sisa = count($calon) - count($ketik);

        return $total / count($ketik) - 0.06 * $sisa;
    }

    private static function miripKata(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        if (strlen($a) >= 2 && $a[0] === $b[0] && strlen($a) < strlen($b) && self::berurutan($a, $b)) {
            return 0.9;
        }

        if (preg_match('/\d/', $a.$b) === 1) {
            return 0.0;
        }

        return self::mirip($a, $b);
    }

    /** Semua huruf $a muncul berurutan di $b. */
    private static function berurutan(string $a, string $b): bool
    {
        $j = 0;

        for ($i = 0, $n = strlen($b); $i < $n && $j < strlen($a); $i++) {
            if ($b[$i] === $a[$j]) {
                $j++;
            }
        }

        return $j === strlen($a);
    }

    /** Jarak Damerau-Levenshtein (versi optimal string alignment). */
    private static function jarak(string $a, string $b): int
    {
        $n = strlen($a);
        $m = strlen($b);
        $d = [];

        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $biaya = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $biaya);

                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
    }
}
