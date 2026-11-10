<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Templat dan pembacaan lembar Excel/CSV berbaris judul untuk impor data.
 * Baris 1 = judul kolom (huruf besar di awal, mis. "Nama bahan"; dibaca tanpa membedakan huruf besar-kecil dan spasi/garis bawah).
 * Templat diberi satu baris contoh di baris 2 (ditandai komentar di sel A2); bila baris 2 file templat masih sama dengan contoh,
 * baris itu dilewati saat dibaca. File tanpa penanda itu (mis. paket data atau CSV) dibaca utuh.
 */
final class LembarExcel
{
    /** Lembar tersembunyi berisi data sistem saat file dibuat (mis. stok saat diunduh), untuk dibandingkan saat diunggah balik. */
    public const LEMBAR_SISTEM = 'Data Sistem';

    public const KETERANGAN_CONTOH = 'Baris contoh: timpa dengan data Anda, atau biarkan saja (baris contoh yang tidak diubah dilewati saat impor).';

    /**
     * Buku Excel dengan tabel berjudul, baris contoh (opsional), isi, dan lembar petunjuk.
     *
     * @param  array<string, array{lebar: int, ket: string, info?: bool, tanggal?: bool, contoh?: mixed}>  $kolom  [kunci => tampilan]
     * @param  list<list<mixed>>  $isi
     * @param  bool  $contoh  sisipkan baris contoh (dari 'contoh' tiap kolom) sebelum isi
     */
    public static function templat(string $judulLembar, array $kolom, array $isi = [], bool $contoh = false): Spreadsheet
    {
        $buku = new Spreadsheet();
        $lembar = $buku->getActiveSheet();
        $lembar->setTitle($judulLembar);
        $lembar->fromArray(array_map(GayaExcel::judulKolom(...), array_keys($kolom)), null, 'A1');

        $tampilan = array_values($kolom);
        $semua = $contoh ? [array_map(fn ($t) => $t['contoh'] ?? null, $tampilan), ...array_values($isi)] : array_values($isi);

        // Teks ditulis apa adanya supaya angka berawalan nol (mis. telepon 0812...) tidak berubah.
        foreach ($semua as $r => $baris) {
            foreach (array_values($baris) as $k => $nilai) {
                $sel = Coordinate::stringFromColumnIndex($k + 1).($r + 2);
                $tanggal = ($tampilan[$k]['tanggal'] ?? false) && is_string($nilai) ? self::tanggal($nilai) : null;

                if ($tanggal !== null) {
                    $lembar->setCellValue($sel, TanggalExcel::PHPToExcel(Carbon::parse($tanggal)->startOfDay()));
                } elseif (is_string($nilai)) {
                    $lembar->setCellValueExplicit($sel, $nilai, DataType::TYPE_STRING);
                } else {
                    $lembar->setCellValue($sel, $nilai);

                    if (is_int($nilai) || is_float($nilai)) {
                        $lembar->getStyle($sel)->getNumberFormat()->setFormatCode(GayaExcel::formatJumlah($nilai));
                    }
                }
            }
        }

        $jumlahKolom = count($kolom);
        $akhir = Coordinate::stringFromColumnIndex($jumlahKolom);
        $barisAkhir = max(count($semua) + 1, 2);

        foreach ($tampilan as $k => $t) {
            $huruf = Coordinate::stringFromColumnIndex($k + 1);
            $lembar->getColumnDimension($huruf)->setWidth($t['lebar']);

            if ($t['tanggal'] ?? false) {
                $lembar->getStyle("{$huruf}2:{$huruf}{$barisAkhir}")->getNumberFormat()->setFormatCode(GayaExcel::FORMAT_TANGGAL);
            }

            if (($t['info'] ?? false) && $semua !== []) {
                $lembar->getStyle("{$huruf}2:{$huruf}{$barisAkhir}")
                    ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(GayaExcel::WARNA_INFO);
            }
        }

        GayaExcel::tabel($lembar, $jumlahKolom, 1, $barisAkhir);

        if ($contoh) {
            $lembar->getStyle("A2:{$akhir}2")->getFont()->setItalic(true)->getColor()->setARGB(GayaExcel::WARNA_REDUP);
            $lembar->getComment('A2')->setWidth('240pt')->setHeight('60pt')->getText()->createTextRun(self::KETERANGAN_CONTOH);
        }

        GayaExcel::cetak($lembar, $jumlahKolom, 1);
        $lembar->freezePane('A2');
        $lembar->setSelectedCell($contoh ? 'A3' : 'A2');

        self::petunjuk($buku, $judulLembar, $kolom, $contoh);
        $buku->setActiveSheetIndex(0);

        return $buku;
    }

    /** Lembar "Petunjuk": tabel kolom, cara isi, dan contoh. */
    private static function petunjuk(Spreadsheet $buku, string $judulLembar, array $kolom, bool $contoh): void
    {
        $petunjuk = $buku->createSheet();
        $petunjuk->setTitle('Petunjuk');
        $petunjuk->fromArray(['Kolom', 'Cara isi', 'Contoh'], null, 'A1');

        $r = 2;
        foreach ($kolom as $kunci => $t) {
            $petunjuk->setCellValue("A{$r}", GayaExcel::judulKolom($kunci));
            $petunjuk->setCellValue("B{$r}", $t['ket']);
            $petunjuk->setCellValueExplicit("C{$r}", self::teksContoh($t), DataType::TYPE_STRING);
            $r++;
        }

        $petunjuk->getStyle('B2:B'.($r - 1))->getAlignment()->setWrapText(true);
        $petunjuk->getColumnDimension('A')->setWidth(24);
        $petunjuk->getColumnDimension('B')->setWidth(72);
        $petunjuk->getColumnDimension('C')->setWidth(20);
        GayaExcel::tabel($petunjuk, 3, 1, $r - 1);

        if ($contoh) {
            $petunjuk->setCellValue('A'.($r + 1), "Baris 2 di lembar {$judulLembar} adalah contoh. Timpa dengan data Anda, atau biarkan saja (baris contoh yang tidak diubah dilewati saat impor).");
            $petunjuk->getStyle('A'.($r + 1))->getFont()->setItalic(true)->getColor()->setARGB(GayaExcel::WARNA_REDUP);
        }

        GayaExcel::cetak($petunjuk, 3, 1);
    }

    /** Contoh satu kolom sebagai teks di petunjuk: tanggal HH/BB/TTTT, angka dengan koma desimal. */
    private static function teksContoh(array $tampilan): string
    {
        $nilai = $tampilan['contoh'] ?? null;
        $tanggal = ($tampilan['tanggal'] ?? false) && is_string($nilai) ? self::tanggal($nilai) : null;

        return match (true) {
            $nilai === null      => '',
            $tanggal !== null    => Carbon::parse($tanggal)->format('d/m/Y'),
            is_numeric($nilai)   => Angka::tampil($nilai),
            default              => (string) $nilai,
        };
    }

    /**
     * Baris berisi dari lembar Excel/CSV, dikunci per kunci kolom. Baris judul kolom dicari sendiri di 30 baris pertama
     * setiap lembar yang terlihat (baris judul/keterangan di atasnya dilewati), dan judul kolom dikenali dari nama
     * kolomnya atau sinonimnya (mis. "Nama Bahan", "Item" untuk bahan; "Qty", "Jumlah" untuk stok). Kolom yang
     * tidak dikenal diabaikan.
     *
     * @param  list<string>  $kolom  kunci kolom yang dibaca
     * @param  list<string|list<string>>  $kolomWajib  kolom wajib; daftar di dalamnya = salah satu cukup
     * @param  array<string, mixed>  $contoh  isi baris contoh templat [kunci => nilai]; baris contoh yang sama persis dilewati
     * @param  bool  $contohDilewati  diisi true bila baris contoh dilewati
     * @param  array<string, list<string>>  $sinonim  [kunci => judul lain yang diterima]
     * @return array<int, array<string, mixed>> [nomor baris di file => isi]
     *
     * @throws DomainException bila file tidak terbaca, baris judul tidak ditemukan, kosong, atau terlalu banyak baris
     */
    /**
     * Baca tabel dari lembar terlihat pertama yang punya baris judul berisi semua kolom wajib. Bila tidak ada, kolom wajib
     * yang judulnya tidak dikenal ditebak dari isinya ($pengenal: daftar nama di sistem, 'angka', atau 'tanggal'),
     * supaya tabel buatan sendiri dengan judul apa pun tetap terbaca. Kolom lain diabaikan.
     *
     * @param  (callable(): array<string, list<string>|string>)|null  $pengenal
     * @param  string|null  $hanyaLembar  baca lembar bernama ini saja (impor sekaligus, satu lembar satu jenis data)
     * @param  array{0: int, 1: int}|null  $rentang  baca baris ke-awal sampai ke-akhir saja (indeks mulai 0; impor sekaligus,
     *                                               beberapa tabel ditumpuk dalam satu lembar). Nomor baris tetap nomor asli.
     * @return array<int, array<string, mixed>> [nomor baris Excel => [kunci => nilai]]
     */
    public static function baca(UploadedFile $file, array $kolom, array $kolomWajib, int $maksBaris, array $contoh = [], ?bool &$contohDilewati = null,
        array $sinonim = [], ?callable $pengenal = null, ?string $hanyaLembar = null, ?array $rentang = null): array
    {
        try {
            $buku = self::muat($file);
        } catch (Throwable) {
            throw new DomainException('File tidak bisa dibaca. Pakai Excel (.xlsx) atau CSV dari templat.');
        }

        $kenal = self::petaJudul($kolom, $sinonim);
        $temuan = null;

        foreach ($buku->getAllSheets() as $lembar) {
            if (! self::lembarDibaca($lembar, $hanyaLembar)) {
                continue;
            }

            $semua = self::dalamRentang($lembar->toArray(null, true, false, false), $rentang);
            $kepala = self::cariKepala($semua, $kenal, $kolomWajib, $rentang[0] ?? 0);

            if ($kepala !== null) {
                $temuan = [$lembar, $semua, ...$kepala];
                break;
            }
        }

        if ($temuan === null && $pengenal !== null && $rentang === null) {
            $cara = $pengenal();

            foreach ($buku->getAllSheets() as $lembar) {
                if (! self::lembarDibaca($lembar, $hanyaLembar)) {
                    continue;
                }

                $semua = $lembar->toArray(null, true, false, false);
                $kepala = self::tebakKepala($semua, $kenal, $kolomWajib, $cara);

                if ($kepala !== null) {
                    $temuan = [$lembar, $semua, ...$kepala];
                    break;
                }
            }
        }

        if ($temuan === null) {
            $wajib = array_map(fn ($w) => GayaExcel::judulKolom(((array) $w)[0]), $kolomWajib);

            throw new DomainException('Baris judul kolom tidak ditemukan. Pastikan ada kolom '.implode(', ', $wajib).'.');
        }

        [$lembar, $semua, $barisKepala, $posisi] = $temuan;
        $selContoh = 'A'.($barisKepala + 2);
        $adaContoh = $contoh !== [] && isset($lembar->getComments()[$selContoh])
            && str_contains($lembar->getComment($selContoh)->getText()->getPlainText(), self::KETERANGAN_CONTOH);
        $hasil = [];
        $contohDilewati = false;

        foreach (array_slice($semua, $barisKepala + 1, null, true) as $i => $sel) {
            $isi = [];
            foreach ($kolom as $k) {
                $nilai = isset($posisi[$k]) ? ($sel[$posisi[$k]] ?? null) : null;
                $isi[$k] = is_string($nilai) ? trim($nilai) : $nilai;
            }

            if (collect($isi)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty() || self::barisTotal($isi)) {
                continue;
            }

            if ($i === $barisKepala + 1 && $adaContoh && self::samaDenganContoh($isi, $contoh)) {
                $contohDilewati = true;

                continue;
            }

            $hasil[$i + 1] = $isi;
        }

        if ($hasil === []) {
            throw new DomainException($contohDilewati
                ? 'File hanya berisi baris contoh. Isi data Anda di bawah judul kolom, lalu unggah lagi.'
                : 'File tidak berisi data.');
        }

        if (count($hasil) > $maksBaris) {
            throw new DomainException('File berisi lebih dari '.$maksBaris.' baris. Pecah jadi beberapa file.');
        }

        return $hasil;
    }

    /**
     * Tulis lembar tersembunyi "Data Sistem": baris 1 = [jenis, json info], baris berikutnya = isi (mis. [nama bahan, stok]).
     * Lembar ini tidak terlihat di Excel dan dibaca kembali saat file diunggah.
     *
     * @param  list<list<mixed>>  $isi
     */
    public static function tulisDataSistem(Spreadsheet $buku, string $jenis, array $info, array $isi): void
    {
        $lembar = $buku->createSheet();
        $lembar->setTitle(self::LEMBAR_SISTEM);
        $lembar->setCellValueExplicit('A1', $jenis, DataType::TYPE_STRING);
        $lembar->setCellValueExplicit('B1', json_encode($info), DataType::TYPE_STRING);

        foreach (array_values($isi) as $r => $baris) {
            foreach (array_values($baris) as $k => $nilai) {
                $sel = Coordinate::stringFromColumnIndex($k + 1).($r + 2);
                is_string($nilai) ? $lembar->setCellValueExplicit($sel, $nilai, DataType::TYPE_STRING) : $lembar->setCellValue($sel, $nilai);
            }
        }

        $lembar->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $buku->setActiveSheetIndex(0);
    }

    /** @return array{info: array, isi: list<list<mixed>>}|null isi lembar "Data Sistem" berjenis $jenis, atau null */
    public static function bacaDataSistem(UploadedFile $file, string $jenis): ?array
    {
        try {
            $lembar = self::muat($file)->getSheetByName(self::LEMBAR_SISTEM);
        } catch (Throwable) {
            return null;
        }

        if (! $lembar || $lembar->getCell('A1')->getValue() !== $jenis) {
            return null;
        }

        $semua = $lembar->toArray(null, false, false, false);
        $info = json_decode((string) ($semua[0][1] ?? ''), true);

        return ['info' => is_array($info) ? $info : [], 'isi' => array_slice($semua, 1)];
    }

    /** Baris di luar rentang dikosongkan (nomor baris tetap). */
    private static function dalamRentang(array $semua, ?array $rentang): array
    {
        if ($rentang === null) {
            return $semua;
        }

        foreach ($semua as $i => $sel) {
            if ($i < $rentang[0] || $i > $rentang[1]) {
                $semua[$i] = [];
            }
        }

        return $semua;
    }

    /** Isi satu lembar terlihat sebagai larik baris (indeks mulai 0), untuk mencari beberapa tabel dalam satu lembar. */
    public static function barisLembar(UploadedFile $file, string $nama): array
    {
        $lembar = self::muat($file)->getSheetByName($nama);

        return $lembar ? $lembar->toArray(null, true, false, false) : [];
    }

    /**
     * Seberapa cocok satu baris sebagai baris judul tabel: jumlah judul kolom yang dikenali, atau 0 bila bukan
     * (kolom wajib tidak lengkap, atau kurang dari separuh isi baris itu yang dikenali).
     */
    public static function skorJudul(array $baris, array $kolom, array $kolomWajib, array $sinonim = []): int
    {
        $kenal = self::petaJudul($kolom, $sinonim);
        $isi = array_filter($baris, fn ($v) => trim((string) $v) !== '');
        $posisi = [];

        foreach ($isi as $k => $judul) {
            $kunci = $kenal[self::rapatkanJudul($judul)] ?? null;

            if ($kunci !== null) {
                $posisi[$kunci] = $k;
            }
        }

        $lengkap = collect($kolomWajib)->every(fn ($w) => collect((array) $w)->contains(fn ($k) => isset($posisi[$k])));

        return $posisi !== [] && $lengkap && count($posisi) * 2 >= count($isi) ? count($posisi) : 0;
    }

    /** Lembar terlihat selain Data Sistem; bila $hanyaLembar diisi, hanya lembar bernama itu. */
    private static function lembarDibaca(Worksheet $lembar, ?string $hanyaLembar): bool
    {
        return $lembar->getSheetState() === Worksheet::SHEETSTATE_VISIBLE && $lembar->getTitle() !== self::LEMBAR_SISTEM
            && ($hanyaLembar === null || $lembar->getTitle() === $hanyaLembar);
    }

    /**
     * Nama lembar terlihat (selain Data Sistem) yang ada isinya, urut seperti di file.
     *
     * @return list<string>
     */
    public static function daftarLembar(UploadedFile $file): array
    {
        try {
            $buku = self::muat($file);
        } catch (Throwable) {
            throw new DomainException('File tidak bisa dibaca. Pakai Excel (.xlsx) atau CSV dari templat.');
        }

        return collect($buku->getAllSheets())
            ->filter(fn (Worksheet $l) => self::lembarDibaca($l, null) && ($l->getHighestDataRow() > 1 || filled($l->getCell('A1')->getValue())))
            ->map(fn (Worksheet $l) => $l->getTitle())
            ->values()
            ->all();
    }

    /**
     * Buka file Excel/CSV. Pemisah CSV dipilih dari koma, titik koma, tab, atau garis tegak (yang paling banyak di baris
     * berisi), bukan tebakan bawaan PhpSpreadsheet yang bisa memilih spasi bila isinya banyak spasi.
     */
    /** File terakhir yang dibuka, supaya file berlembar banyak tidak dibuka ulang untuk setiap lembar. */
    private static ?array $terakhir = null;

    private static function muat(UploadedFile $file): Spreadsheet
    {
        $jalur = $file->getRealPath();
        $kunci = $jalur.'|'.@filemtime($jalur).'|'.@filesize($jalur).'|'.md5_file($jalur);

        if (self::$terakhir !== null && self::$terakhir[0] === $kunci) {
            return self::$terakhir[1];
        }

        $buku = self::muatBaru($jalur);
        self::$terakhir = [$kunci, $buku];

        return $buku;
    }

    private static function muatBaru(string $jalur): Spreadsheet
    {
        $jenis = IOFactory::identify($jalur);

        if ($jenis !== 'Csv') {
            return IOFactory::load($jalur);
        }

        $baris = array_slice(array_filter(preg_split('/\R/', (string) file_get_contents($jalur, length: 65536)), fn ($b) => trim($b) !== ''), 0, 20);
        $hitung = collect([',', ';', "\t", '|'])->mapWithKeys(fn ($p) => [$p => array_sum(array_map(fn ($b) => substr_count($b, $p), $baris))]);
        $pemisah = $hitung->max() > 0 ? $hitung->sortDesc()->keys()->first() : ',';

        $pembaca = IOFactory::createReader('Csv');
        $pembaca->setDelimiter($pemisah);

        return $pembaca->load($jalur);
    }

    /** Baris total di bawah tabel unduhan (teks satu-satunya "Total"/"Jumlah", sisanya angka atau kosong). */
    private static function barisTotal(array $isi): bool
    {
        $teks = collect($isi)->filter(fn ($v) => is_string($v) && trim($v) !== '' && ! is_numeric($v))->values();

        return $teks->count() === 1 && in_array(mb_strtolower(trim($teks[0])), ['total', 'jumlah', 'grand total', 'total keseluruhan'], true);
    }

    /** Judul kolom dirapatkan untuk dibandingkan: huruf kecil, tanpa isi kurung, spasi, dan tanda baca ("Stok (kg)" -> "stok"). */
    public static function rapatkanJudul(mixed $judul): string
    {
        $teks = mb_strtolower(Str::ascii((string) $judul));
        $teks = preg_replace('/\([^)]*\)|\[[^\]]*\]/', '', $teks);

        return preg_replace('/[^a-z0-9]/', '', $teks);
    }

    /** @return array<string, string> [judul rapat => kunci]; kunci kolom sendiri didahulukan, lalu sinonim sesuai urutan */
    private static function petaJudul(array $kolom, array $sinonim): array
    {
        $peta = [];

        foreach ($kolom as $k) {
            $peta[self::rapatkanJudul($k)] ??= $k;
            $peta[self::rapatkanJudul(GayaExcel::judulKolom($k))] ??= $k;
        }

        foreach ($kolom as $k) {
            foreach ($sinonim[$k] ?? [] as $lain) {
                $peta[self::rapatkanJudul($lain)] ??= $k;
            }
        }

        return $peta;
    }

    /**
     * Baris judul pertama (dalam 30 baris) yang memuat semua kolom wajib.
     *
     * @return array{0: int, 1: array<string, int>}|null [indeks baris judul, [kunci => indeks kolom]]
     */
    private static function cariKepala(array $semua, array $kenal, array $kolomWajib, int $mulai = 0): ?array
    {
        foreach (array_slice($semua, $mulai, 30, true) as $i => $sel) {
            $posisi = [];

            foreach ($sel as $k => $judul) {
                $kunci = $kenal[self::rapatkanJudul($judul)] ?? null;

                if ($kunci !== null && ! isset($posisi[$kunci])) {
                    $posisi[$kunci] = $k;
                }
            }

            $lengkap = collect($kolomWajib)->every(fn ($w) => collect((array) $w)->contains(fn ($k) => isset($posisi[$k])));

            if ($posisi !== [] && $lengkap) {
                return [$i, $posisi];
            }
        }

        return null;
    }

    /**
     * Tebak letak kolom wajib dari isi tabel. Baris judul = baris dengan judul dikenal terbanyak (bila ada); kolom wajib
     * yang belum ketemu dicari dari isinya: kolom nama (mis. bahan) = kolom yang isinya paling banyak cocok dengan nama
     * di sistem, kolom angka = satu-satunya kolom lain yang isinya angka, kolom tanggal = kolom yang isinya tanggal.
     * Bila kolom angkanya lebih dari satu, dipilih yang judulnya memuat kata kolom itu (mis. "Terjual Hari Ini" untuk porsi),
     * lalu kolom nomor, kode, harga, dan total disisihkan. Tebakan dibatalkan bila kolom nama tidak memuat satu pun nama
     * yang ada di sistem (file yang bukan tabel stok).
     *
     * @param  array<string, list<string>|string>  $cara
     * @return array{0: int, 1: array<string, int>}|null
     *
     * @throws DomainException bila ada lebih dari satu kolom angka yang bisa dipakai
     */
    private static function tebakKepala(array $semua, array $kenal, array $kolomWajib, array $cara): ?array
    {
        $semua = array_values($semua);
        [$kepala, $posisi] = [-1, []];

        foreach (array_slice($semua, 0, 30, true) as $i => $sel) {
            $dikenal = [];
            foreach ($sel as $k => $judul) {
                $kunci = $kenal[self::rapatkanJudul($judul)] ?? null;
                if ($kunci !== null && ! isset($dikenal[$kunci])) {
                    $dikenal[$kunci] = $k;
                }
            }
            if (count($dikenal) > count($posisi)) {
                [$kepala, $posisi] = [$i, $dikenal];
            }
        }

        $kurang = collect($kolomWajib)
            ->reject(fn ($w) => collect((array) $w)->contains(fn ($k) => isset($posisi[$k])))
            ->map(fn ($w) => collect((array) $w)->first(fn ($k) => isset($cara[$k])))
            ->values();

        if ($kurang->isEmpty() || $kurang->contains(null)) {
            return null;
        }

        $kolomIsi = fn (int $mulai) => collect(array_slice($semua, $mulai + 1))->map(fn ($sel) => array_values((array) $sel));
        $jumlahKolom = max(array_map(fn ($sel) => count((array) $sel), $semua) ?: [0]);
        $terpakai = array_flip($posisi);

        foreach ($kurang->filter(fn ($k) => is_array($cara[$k])) as $k) {
            $nama = array_flip(array_map(fn ($n) => self::rapatkanJudul($n), $cara[$k]));
            $terbaik = null;

            for ($kol = 0; $kol < $jumlahKolom; $kol++) {
                if (isset($terpakai[$kol])) {
                    continue;
                }
                $cocok = $kolomIsi($kepala)->filter(fn ($sel) => isset($nama[self::rapatkanJudul($sel[$kol] ?? '')]) && filled($sel[$kol] ?? null));
                $terisi = $kolomIsi($kepala)->filter(fn ($sel) => filled($sel[$kol] ?? null))->count();
                if ($cocok->isNotEmpty() && $cocok->count() * 2 >= $terisi && $cocok->count() > ($terbaik[1] ?? 0)) {
                    $terbaik = [$kol, $cocok->count(), $cocok->keys()->first()];
                }
            }

            if ($terbaik === null) {
                return null;
            }

            $posisi[$k] = $terbaik[0];
            $terpakai[$terbaik[0]] = $k;

            if ($kepala < 0) {
                $kepala = $terbaik[2] - 1;
            }
        }

        $judulKolom = fn (int $k) => $kepala >= 0 && filled($semua[$kepala][$k] ?? null)
            ? (string) $semua[$kepala][$k]
            : Coordinate::stringFromColumnIndex($k + 1);

        foreach ($kurang->reject(fn ($k) => is_array($cara[$k])) as $k) {
            $calon = [];

            for ($kol = 0; $kol < $jumlahKolom; $kol++) {
                if (isset($terpakai[$kol])) {
                    continue;
                }
                $isi = $kolomIsi($kepala)->map(fn ($sel) => $sel[$kol] ?? null)->filter(fn ($v) => filled($v));
                $sesuai = $isi->filter(fn ($v) => $cara[$k] === 'tanggal' ? self::tanggal($v) !== null
                    : is_numeric($v) || (is_string($v) && JumlahPintar::pecah($v) !== null));
                if ($isi->isNotEmpty() && $sesuai->count() >= $isi->count() * 0.8) {
                    $calon[] = $kol;
                }
            }

            if ($calon === []) {
                return null;
            }

            // Dua kolom angka: jumlah (angka biasa) dan isi bersatuan (mis. 2 dan "10 Liter") = 2 x 10 liter.
            if (count($calon) === 2 && $cara[$k] !== 'tanggal' && in_array('isi_kemasan', $kenal, true) && ! isset($posisi['isi_kemasan'])) {
                $bersatuan = fn (int $kol) => $kolomIsi($kepala)->contains(fn ($sel) => is_string($sel[$kol] ?? null)
                    && (JumlahPintar::pecah($sel[$kol])[1] ?? null) !== null);
                $isiKol = array_values(array_filter($calon, $bersatuan));

                if (count($isiKol) === 1) {
                    $posisi['isi_kemasan'] = $isiKol[0];
                    $terpakai[$isiKol[0]] = 'isi_kemasan';
                    $calon = array_values(array_diff($calon, $isiKol));
                }
            }

            if (count($calon) > 1 && $kepala >= 0) {
                $judulRapat = fn (int $kol) => self::rapatkanJudul($semua[$kepala][$kol] ?? '');
                $kata = array_keys(array_filter($kenal, fn ($kunci) => $kunci === $k));
                $mirip = array_values(array_filter($calon, fn ($kol) => $judulRapat($kol) !== ''
                    && collect($kata)->contains(fn ($w) => $w !== '' && str_contains($judulRapat($kol), $w))));
                $calon = count($mirip) === 1 ? $mirip : array_values(array_filter($calon,
                    fn ($kol) => ! preg_match('/^(no|nomor|id|kode|harga|price|total|subtotal|telepon|telp|hp|diskon|rp)/', $judulRapat($kol))));
                $calon = $calon === [] ? $mirip : $calon;
            }

            if ($calon === []) {
                return null;
            }

            if (count($calon) > 1) {
                throw new DomainException('Ada beberapa kolom '.($cara[$k] === 'tanggal' ? 'tanggal' : 'angka').' ('
                    .implode(', ', array_map($judulKolom, $calon)).'), jadi kolom '.GayaExcel::judulKolom($k)
                    .' tidak bisa ditebak. Ganti judul kolomnya menjadi "'.GayaExcel::judulKolom($k).'".');
            }

            $posisi[$k] = $calon[0];
            $terpakai[$calon[0]] = $k;
        }

        foreach (array_keys(array_filter($cara, 'is_array')) as $k) {
            if (! isset($posisi[$k])) {
                continue;
            }
            $nama = array_flip(array_map(fn ($n) => self::rapatkanJudul($n), $cara[$k]));
            if ($kolomIsi($kepala)->doesntContain(fn ($sel) => isset($nama[self::rapatkanJudul($sel[$posisi[$k]] ?? '')]))) {
                return null;
            }
        }

        return [$kepala, $posisi];
    }

    /** Isi baris sama dengan baris contoh pada semua kolom contoh (huruf besar-kecil dan format angka diabaikan). */
    private static function samaDenganContoh(array $isi, array $contoh): bool
    {
        foreach ($contoh as $kunci => $nilai) {
            $baca = $isi[$kunci] ?? null;

            if (is_numeric($nilai) || is_numeric($baca)) {
                if (! is_numeric($baca) || ! is_numeric($nilai) || abs((float) $baca - (float) $nilai) > 0.0005) {
                    return false;
                }

                continue;
            }

            if (mb_strtolower(Str::squish((string) $baca)) !== mb_strtolower(Str::squish((string) $nilai))) {
                return false;
            }
        }

        return true;
    }

    /** Tanggal dari sel Excel (angka seri) atau teks dalam berbagai format (lihat PembersihExcel::tanggal); null bila tidak terbaca. */
    public static function tanggal(mixed $nilai): ?string
    {
        return PembersihExcel::tanggal($nilai);
    }
}
