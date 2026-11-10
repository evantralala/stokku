<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Gaya bersama semua berkas Excel (unduhan, templat, ekspor): tabel Excel bergaris selang-seling dengan saringan,
 * format angka & tanggal Indonesia, kepala tabel rapi, dan pengaturan cetak.
 */
final class GayaExcel
{
    public const FORMAT_BULAT = '#,##0';
    public const FORMAT_DESIMAL = '#,##0.###';
    public const FORMAT_PERSEN = '0.0%';
    public const FORMAT_TANGGAL = 'dd/mm/yyyy';
    public const FORMAT_WAKTU = 'dd/mm/yyyy hh:mm';

    public const WARNA_REDUP = 'FF6B6B6B';
    public const WARNA_INFO = 'FFF3F1EC';
    private const WARNA_KEPALA = 'FF1A1917';
    private const WARNA_GARIS = 'FFD6D1C7';

    private const GAYA_TABEL = TableStyle::TABLE_STYLE_MEDIUM1;
    private const TINGGI_KEPALA = 30;

    /** Kata depan dan kata hubung yang tetap huruf kecil di judul kolom. */
    private const KATA_KECIL = ['dan', 'atau', 'di', 'ke', 'dari', 'untuk', 'yang', 'dengan', 'per', 'pada', 'oleh'];

    /** Judul kolom dari kunci impor, mis. "isi_per_satuan_beli" -> "Isi per Satuan Beli". */
    public static function judulKolom(string $kunci): string
    {
        $kata = explode(' ', str_replace('_', ' ', $kunci));

        return implode(' ', array_map(
            fn (string $k, int $i) => $i > 0 && in_array($k, self::KATA_KECIL, true) ? $k : mb_strtoupper(mb_substr($k, 0, 1)).mb_substr($k, 1),
            $kata,
            array_keys($kata)
        ));
    }

    /**
     * Jadikan rentang (baris kepala sampai baris data terakhir) sebagai tabel Excel bernama.
     * Nama tabel unik per buku: "Tabel" + urutan lembar.
     */
    public static function tabel(Worksheet $lembar, int $kolomAkhir, int $barisKepala, int $barisAkhir): void
    {
        $huruf = Coordinate::stringFromColumnIndex($kolomAkhir);
        $barisAkhir = max($barisAkhir, $barisKepala + 1);

        $tabel = new Table("A{$barisKepala}:{$huruf}{$barisAkhir}", 'Tabel'.($lembar->getParent()->getIndex($lembar) + 1));
        $tabel->setStyle((new TableStyle())->setTheme(self::GAYA_TABEL)->setShowRowStripes(true));
        $lembar->addTable($tabel);

        // Warna kepala dan garis juga ditulis langsung supaya tetap rapi di aplikasi yang tidak membaca gaya tabel Excel.
        $kepala = $lembar->getStyle("A{$barisKepala}:{$huruf}{$barisKepala}");
        $kepala->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $kepala->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::WARNA_KEPALA);
        $kepala->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $lembar->getRowDimension($barisKepala)->setRowHeight(self::TINGGI_KEPALA);

        $isi = $lembar->getStyle("A{$barisKepala}:{$huruf}{$barisAkhir}");
        $isi->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $isi->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::WARNA_GARIS);
    }

    /** Lebar kolom dari teks terpanjang (judul atau isi), dengan ruang sisa; dibatasi supaya teks panjang tidak melebar. */
    public static function lebarKolom(Worksheet $lembar, string $huruf, string $judul, iterable $nilai): void
    {
        $panjang = mb_strlen($judul);

        foreach ($nilai as $n) {
            $teks = is_float($n) || is_int($n) ? Angka::tampil($n) : (string) $n;
            $panjang = max($panjang, mb_strlen($teks));
        }

        $lembar->getColumnDimension($huruf)->setWidth(min(max($panjang + 3, 10), 60));
    }

    /** Format angka satu sel: bilangan bulat tanpa koma, pecahan sampai 3 angka di belakang koma. */
    public static function formatJumlah(mixed $nilai): string
    {
        return is_numeric($nilai) && floor((float) $nilai) != (float) $nilai ? self::FORMAT_DESIMAL : self::FORMAT_BULAT;
    }

    /** Garis atas tebal untuk baris total di bawah tabel. */
    public static function barisTotal(Worksheet $lembar, string $rentang): void
    {
        $gaya = $lembar->getStyle($rentang);
        $gaya->getFont()->setBold(true);
        $gaya->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
    }

    /** Cetak: mendatar bila kolom banyak, muat selebar kertas, kepala tabel diulang di setiap halaman. */
    public static function cetak(Worksheet $lembar, int $jumlahKolom, int $barisKepala): void
    {
        $halaman = $lembar->getPageSetup();
        $halaman->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($jumlahKolom > 6 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($barisKepala, $barisKepala);
        $lembar->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);
        $lembar->getHeaderFooter()->setOddFooter('&L&8'.str_replace('&', '&&', Identitas::aplikasi()).'&R&8Halaman &P dari &N');
    }
}
