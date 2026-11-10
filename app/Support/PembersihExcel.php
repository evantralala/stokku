<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use Throwable;

/**
 * Pembersih isian sel Excel tanpa AI: spasi, huruf besar-kecil nama, angka gaya Indonesia/Inggris/pecahan,
 * berbagai format tanggal, dan sinonim satuan. Semua fungsi murni (tanpa database).
 */
final class PembersihExcel
{
    /** Kata sambung yang tetap huruf kecil di tengah nama. */
    private const KATA_KECIL = ['dan', 'di', 'ke', 'dari', 'untuk', 'atau', 'dengan', 'yang', '&', 'and', 'of', 'with'];

    private const PECAHAN = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75, '⅓' => 1 / 3, '⅔' => 2 / 3, '⅛' => 0.125, '⅕' => 0.2];

    private const BULAN = [
        'jan' => 1, 'januari' => 1, 'january' => 1,
        'feb' => 2, 'februari' => 2, 'february' => 2, 'pebruari' => 2,
        'mar' => 3, 'maret' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5,
        'jun' => 6, 'juni' => 6, 'june' => 6,
        'jul' => 7, 'juli' => 7, 'july' => 7,
        'agu' => 8, 'agt' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8, 'ags' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11, 'nop' => 11, 'nopember' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12, 'december' => 12,
    ];

    /**
     * Kelompok sinonim satuan: [nama baku => variasi tulisan]. Satuan yang ada di sistem selalu didahulukan.
     */
    public const SINONIM_SATUAN = [
        'kg'     => ['kg', 'kgs', 'kilo', 'kilogram', 'kilogr', 'kilos'],
        'gram'   => ['gram', 'gr', 'grm', 'g', 'grams', 'gramm'],
        'ons'    => ['ons', 'on'],
        'liter'  => ['liter', 'litre', 'ltr', 'lt', 'l', 'ltrs'],
        'ml'     => ['ml', 'mililiter', 'milliliter', 'millilitre', 'mili', 'cc'],
        'pcs'    => ['pcs', 'pc', 'piece', 'pieces', 'buah', 'bh', 'biji', 'bj'],
        'butir'  => ['butir', 'btr'],
        'pack'   => ['pack', 'pak', 'pck', 'pk', 'packs'],
        'karton' => ['karton', 'krt', 'ctn', 'carton'],
        'botol'  => ['botol', 'btl', 'bottle'],
        'kaleng' => ['kaleng', 'klg', 'can'],
        'lembar' => ['lembar', 'lbr', 'sheet'],
        'ikat'   => ['ikat', 'ikt'],
        'sachet' => ['sachet', 'sct', 'saset'],
        'karung' => ['karung', 'krg', 'sak'],
        'kotak'  => ['kotak', 'box', 'ktk'],
        'tray'   => ['tray', 'try'],
        'pasang' => ['pasang', 'psg', 'pair'],
    ];

    /** Spasi dirapikan (termasuk spasi tak terlihat dari salin-tempel); kosong menjadi null. */
    public static function teks(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $teks = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}", "\t", "\r", "\n"], ' ', (string) $nilai);
        $teks = Str::squish($teks);

        return $teks === '' ? null : $teks;
    }

    /**
     * Huruf besar di awal kata bila nama ditulis seluruhnya kecil atau seluruhnya besar:
     * "beras jepang" / "BERAS JEPANG" -> "Beras Jepang", "air mineral 600 ML" -> "Air Mineral 600 ml".
     * Nama yang sudah campuran ("iPhone", "Beras jepang") dibiarkan.
     */
    public static function kapital(?string $teks): ?string
    {
        if ($teks === null || $teks === '') {
            return $teks;
        }

        $satuan = self::petaSinonim();
        $kata = explode(' ', $teks);

        foreach ($kata as $i => $k) {
            if ($i > 0 && preg_match('/\d$/u', $kata[$i - 1]) === 1 && isset($satuan[mb_strtolower($k)])) {
                $kata[$i] = mb_strtolower($k);
            }
        }

        $teks = implode(' ', $kata);
        $huruf = preg_replace('/[^\p{L}]/u', '', implode('', array_filter($kata, fn ($k, $i) => ! ($i > 0 && preg_match('/\d$/u', $kata[$i - 1]) === 1 && isset($satuan[$k])), ARRAY_FILTER_USE_BOTH)));

        if ($huruf === '' || ($huruf !== mb_strtolower($huruf) && $huruf !== mb_strtoupper($huruf))) {
            return $teks;
        }

        $kata = explode(' ', mb_strtolower($teks));

        foreach ($kata as $i => $k) {
            $sebelumAngka = $i > 0 && preg_match('/\d$/u', $kata[$i - 1]) === 1;

            if (($sebelumAngka && isset($satuan[$k])) || preg_match('/^\d/u', $k) === 1) {
                continue;
            }

            if ($i > 0 && in_array($k, self::KATA_KECIL, true)) {
                continue;
            }

            $kata[$i] = implode('-', array_map(fn (string $b) => mb_strtoupper(mb_substr($b, 0, 1)).mb_substr($b, 1), explode('-', $k)));
        }

        return implode(' ', $kata);
    }

    /**
     * Angka dari sel: angka asli dibiarkan; teks "2,5", "2.5", "1.000", "1.250,75", "1,250.75", "2½", "1 1/2", "Rp 5.000".
     * Hasil berupa int/float, atau null bila bukan angka.
     */
    public static function angka(mixed $nilai): int|float|null
    {
        if (is_int($nilai) || is_float($nilai)) {
            return $nilai;
        }

        $teks = self::teks($nilai);

        if ($teks === null) {
            return null;
        }

        $teks = preg_replace('/^rp\.?\s*/iu', '', $teks);
        $negatif = str_starts_with($teks, '-');
        $teks = ltrim($teks, '-+ ');

        $pecahan = 0.0;
        foreach (self::PECAHAN as $simbol => $nilaiPecahan) {
            if (str_ends_with($teks, $simbol)) {
                $pecahan = $nilaiPecahan;
                $teks = trim(mb_substr($teks, 0, -1));
                break;
            }
        }

        if (preg_match('#^(?:(\d+)\s+)?(\d+)\s*/\s*(\d+)$#', $teks, $m) === 1) {
            if ((int) $m[3] === 0 || $pecahan > 0) {
                return null;
            }

            $hasil = (float) ($m[1] ?? 0) + (int) $m[2] / (int) $m[3];

            return self::bulatkan($negatif ? -$hasil : $hasil);
        }

        if ($teks === '' && $pecahan > 0) {
            return self::bulatkan($negatif ? -$pecahan : $pecahan);
        }

        $teks = str_replace(' ', '', $teks);
        $standar = self::angkaStandar($teks);

        if ($standar === null) {
            return null;
        }

        $hasil = (float) $standar + $pecahan;

        if ($negatif) {
            $hasil = -$hasil;
        }

        return self::bulatkan($hasil);
    }

    /** Teks angka tanpa spasi -> "1234.5" (titik desimal), atau null. */
    private static function angkaStandar(string $teks): ?string
    {
        if (preg_match('/^\d+$/', $teks) === 1) {
            return $teks;
        }

        $titik = substr_count($teks, '.');
        $koma = substr_count($teks, ',');

        if (preg_match('/^[\d.,]+$/', $teks) !== 1 || ! preg_match('/^\d/', $teks)) {
            return null;
        }

        if ($titik > 0 && $koma > 0) {
            $desimalKoma = strrpos($teks, ',') > strrpos($teks, '.');
            [$ribuan, $desimal] = $desimalKoma ? ['.', ','] : [',', '.'];

            if (substr_count($teks, $desimal) > 1 || preg_match('/^\d{1,3}(\\'.$ribuan.'\d{3})*\\'.$desimal.'\d+$/', $teks) !== 1) {
                return null;
            }

            return str_replace([$ribuan, $desimal], ['', '.'], $teks);
        }

        $pemisah = $titik > 0 ? '.' : ',';
        $jumlah = max($titik, $koma);

        if ($jumlah > 1) {
            return preg_match('/^\d{1,3}(\\'.$pemisah.'\d{3})+$/', $teks) === 1 ? str_replace($pemisah, '', $teks) : null;
        }

        [$depan, $belakang] = explode($pemisah, $teks);

        if ($belakang === '') {
            return null;
        }

        // "1.000" / "12.500" = ribuan (gaya Indonesia); "0.500" dan "2.5" = desimal. Koma selalu desimal bila tunggal.
        if ($pemisah === '.' && strlen($belakang) === 3 && $depan !== '0') {
            return $depan.$belakang;
        }

        return $depan.'.'.$belakang;
    }

    private static function bulatkan(float $nilai): int|float
    {
        $nilai = round($nilai, 6);

        return floor($nilai) === $nilai && abs($nilai) < PHP_INT_MAX ? (int) $nilai : $nilai;
    }

    /**
     * Tanggal dari sel: angka seri Excel, objek tanggal, atau teks 16/10/2026, 16-10-26, 16.10.2026, 2026-10-16,
     * 2026/10/16, 16 Okt 2026, 16 Oktober 2026 (boleh berjam). Hasil "Y-m-d" atau null bila tidak terbaca.
     */
    public static function tanggal(mixed $nilai): ?string
    {
        if ($nilai instanceof DateTimeInterface) {
            return Carbon::instance($nilai)->toDateString();
        }

        if (is_int($nilai) || is_float($nilai) || (is_string($nilai) && preg_match('/^\d{5}(\.\d+)?$/', trim($nilai)) === 1)) {
            $seri = (float) $nilai;

            if ($seri < 1 || $seri > 2958465) {
                return null;
            }

            try {
                return Carbon::instance(TanggalExcel::excelToDateTimeObject($seri))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        $teks = self::teks($nilai);

        if ($teks === null) {
            return null;
        }

        $teks = mb_strtolower(preg_replace('/[\sT]+\d{1,2}[:.]\d{2}([:.]\d{2})?(\s*(am|pm|wib))?$/iu', '', $teks));

        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $teks, $m) === 1) {
            return self::susunTanggal((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2}|\d{4})$#', $teks, $m) === 1) {
            return self::susunTanggal(self::tahun($m[3]), (int) $m[2], (int) $m[1]);
        }

        if (preg_match('#^(\d{1,2})[\s\-/.]+([a-z]+)\.?[\s\-/.,]+(\d{2}|\d{4})$#u', $teks, $m) === 1 && isset(self::BULAN[$m[2]])) {
            return self::susunTanggal(self::tahun($m[3]), self::BULAN[$m[2]], (int) $m[1]);
        }

        return null;
    }

    private static function tahun(string $teks): int
    {
        return strlen($teks) === 2 ? 2000 + (int) $teks : (int) $teks;
    }

    private static function susunTanggal(int $tahun, int $bulan, int $hari): ?string
    {
        return checkdate($bulan, $hari, $tahun) ? sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari) : null;
    }

    /**
     * Nama satuan baku: "Kg", "kilo", "KG" -> "kg". Satuan yang sudah ada di sistem didahulukan
     * (mis. sistem memakai "Kg" maka "kilo" -> "Kg"). Satuan yang tidak dikenal ditulis huruf kecil.
     *
     * @param  list<string>  $diSistem  nama satuan yang ada di sistem
     */
    public static function satuan(mixed $nilai, array $diSistem = []): ?string
    {
        $teks = self::teks($nilai);

        if ($teks === null) {
            return null;
        }

        $kunci = rtrim(mb_strtolower($teks), '.');
        $sistem = collect($diSistem)->keyBy(fn (string $s) => mb_strtolower($s));

        if ($sistem->has($kunci)) {
            return $sistem[$kunci];
        }

        $baku = self::petaSinonim()[$kunci] ?? null;

        if ($baku === null) {
            return $kunci;
        }

        foreach ([$baku, ...self::SINONIM_SATUAN[$baku]] as $variasi) {
            if ($sistem->has($variasi)) {
                return $sistem[$variasi];
            }
        }

        return $baku;
    }

    /** @return array<string, string> [variasi => nama baku] */
    private static function petaSinonim(): array
    {
        static $peta = null;

        if ($peta === null) {
            $peta = [];
            foreach (self::SINONIM_SATUAN as $baku => $daftar) {
                foreach ($daftar as $v) {
                    $peta[$v] = $baku;
                }
            }
        }

        return $peta;
    }
}
