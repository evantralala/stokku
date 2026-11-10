<?php

namespace App\Support;

use App\Models\BahanBaku;

/**
 * Jumlah yang ditulis bebas: "38", "2x19", "2 x 10 liter", "500 ml", "1,5 kg". Perkalian dihitung, satuan
 * dikonversi ke satuan bahan (kg <-> gram <-> ons, liter <-> ml), dan satuan resep bahan dikenali.
 */
class JumlahPintar
{
    /** Satuan ukur baku => [kelompok, nilai dalam satuan terkecil kelompoknya] */
    private const UKURAN = [
        'kg'    => ['berat', 1000],
        'ons'   => ['berat', 100],
        'gram'  => ['berat', 1],
        'liter' => ['isi', 1000],
        'ml'    => ['isi', 1],
    ];

    /**
     * Angka dan satuannya, atau null bila bukan jumlah. Satuan sudah dibakukan ("Liter", "ltr" -> "liter").
     *
     * @return array{0: float, 1: ?string}|null
     */
    public static function pecah(mixed $nilai): ?array
    {
        if (is_int($nilai) || is_float($nilai)) {
            return [(float) $nilai, null];
        }

        $teks = PembersihExcel::teks($nilai);

        if ($teks === null) {
            return null;
        }

        $hasil = 1.0;
        $satuan = null;

        foreach (preg_split('/(?<=[\d\s])[x×*](?=\s*\d)/iu', $teks) as $bagian) {
            if (preg_match('/^\s*([-+]?[\d.,\/½¼¾\s]*\d[½¼¾]?)\s*([^\d\s].*)?$/u', $bagian, $m) !== 1) {
                return null;
            }

            $angka = PembersihExcel::angka(trim($m[1]));
            $nama = isset($m[2]) ? PembersihExcel::satuan(rtrim(trim($m[2]), '.')) : null;

            if ($angka === null || ($nama !== null && $satuan !== null)) {
                return null;
            }

            $hasil *= $angka;
            $satuan ??= $nama;
        }

        return [round($hasil, 6), $satuan];
    }

    /**
     * Jumlah dalam satuan bahan.
     *
     * @return array{0: ?float, 1: ?string, 2: ?string} [jumlah, pesan salah, catatan bila dihitung/dikonversi]
     */
    public static function keSatuanBahan(mixed $nilai, BahanBaku $bahan): array
    {
        $pecah = self::pecah($nilai);

        if ($pecah === null) {
            return [null, null, null];
        }

        [$angka, $satuan] = $pecah;
        $beli = PembersihExcel::satuan($bahan->satuanBeli->nama);
        $dasar = PembersihExcel::satuan($bahan->satuanDasar->nama);
        $isi = (float) $bahan->isi_per_satuan_beli ?: 1.0;
        $hasil = match (true) {
            $satuan === null, $satuan === $beli => $angka,
            $satuan === $dasar                  => $angka / $isi,
            self::sekelompok($satuan, $beli)     => $angka * self::UKURAN[$satuan][1] / self::UKURAN[$beli][1],
            self::sekelompok($satuan, $dasar)    => $angka * self::UKURAN[$satuan][1] / self::UKURAN[$dasar][1] / $isi,
            default                             => null,
        };

        if ($hasil === null) {
            return [null, "Satuan {$satuan} tidak cocok dengan {$bahan->nama} ({$bahan->satuanBeli->nama}).", null];
        }

        $hasil = round($hasil, 3);
        $teks = is_string($nilai) ? PembersihExcel::teks($nilai) : null;
        $catatan = $teks !== null && ! is_numeric(str_replace(',', '.', $teks))
            ? $teks.' = '.Angka::tampil($hasil).' '.$bahan->satuanBeli->nama
            : null;

        return [$hasil, null, $catatan];
    }

    /**
     * Isian form: angka biasa dibaca seperti Angka::baca; perkalian dan satuan dihitung ke satuan bahan (bila bahannya
     * diketahui). Bila tidak terbaca, isian dikembalikan apa adanya supaya validasi menampilkan pesan salah.
     */
    public static function isian(mixed $nilai, mixed $bahanId = null): mixed
    {
        $biasa = Angka::baca($nilai);

        if (! is_string($nilai) || is_numeric($biasa) || trim($nilai) === '') {
            return $biasa;
        }

        $bahan = is_numeric($bahanId) ? BahanBaku::with('satuanBeli', 'satuanDasar')->find((int) $bahanId) : null;

        if ($bahan) {
            return self::keSatuanBahan($nilai, $bahan)[0] ?? $nilai;
        }

        $pecah = self::pecah($nilai);

        return $pecah !== null && $pecah[1] === null ? $pecah[0] : $nilai;
    }

    /**
     * Isian per baris dokumen (opname, terima kiriman, keputusan permintaan): kunci = id baris detail, bahannya
     * diambil dari model detail itu supaya satuan (mis. "500 ml") ikut dikonversi.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelDetail
     * @return array<int|string, mixed>
     */
    public static function isianPerDetail(array $isian, string $modelDetail, string $kolom = 'qty'): array
    {
        $ids = array_filter(array_keys($isian), 'is_numeric');
        $bahan = $ids === [] ? collect() : $modelDetail::query()->whereKey($ids)->pluck('bahan_baku_id', 'id');

        foreach ($isian as $id => $baris) {
            if (is_array($baris) && array_key_exists($kolom, $baris)) {
                $isian[$id][$kolom] = self::isian($baris[$kolom], $bahan[$id] ?? null);
            }
        }

        return $isian;
    }

    /**
     * Baris form dengan bahan yang sama digabung: jumlahnya ditambahkan, isian lain memakai yang pertama terisi.
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int, array<string, mixed>>
     */
    public static function gabungBaris(array $baris, string $kolom = 'qty'): array
    {
        $hasil = [];
        $posisi = [];

        foreach ($baris as $i => $b) {
            $id = $b['bahan_baku_id'] ?? null;
            $j = is_numeric($id) ? ($posisi[(int) $id] ?? null) : null;

            if ($j === null || ! is_numeric($hasil[$j][$kolom] ?? null) || ! is_numeric($b[$kolom] ?? null)) {
                $hasil[$i] = $b;
                if (is_numeric($id)) {
                    $posisi[(int) $id] ??= $i;
                }

                continue;
            }

            $hasil[$j][$kolom] = round((float) $hasil[$j][$kolom] + (float) $b[$kolom], 3);
            foreach ($b as $k => $v) {
                if (blank($hasil[$j][$k] ?? null) && filled($v)) {
                    $hasil[$j][$k] = $v;
                }
            }
        }

        return $hasil;
    }

    private static function sekelompok(?string $a, string $b): bool
    {
        return isset(self::UKURAN[$a], self::UKURAN[$b]) && self::UKURAN[$a][0] === self::UKURAN[$b][0];
    }
}
