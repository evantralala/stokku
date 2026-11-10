<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas Excel unduhan (laporan dan daftar): lembar berjudul, tabel Excel (GayaExcel) dengan format angka/tanggal,
 * baris total, dan kepala yang dibekukan.
 */
final class BukuExcel
{
    /** Tipe kolom: jumlah (desimal), bulat, persen, tanggal, waktu (tanggal + jam), atau teks (null). */
    public const JUMLAH = 'jumlah';
    public const BULAT = 'bulat';
    public const PERSEN = 'persen';
    public const TANGGAL = 'tanggal';
    public const WAKTU = 'waktu';

    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const BARIS_KEPALA = 5;

    public static function baru(string $judul): Spreadsheet
    {
        $buku = new Spreadsheet();
        $buku->removeSheetByIndex(0);
        $buku->getProperties()->setCreator(Identitas::aplikasi())->setTitle($judul);

        return $buku;
    }

    public static function unduh(Spreadsheet $buku, string $namaBerkas): StreamedResponse
    {
        $buku->setActiveSheetIndex(0);

        return response()->streamDownload(
            fn () => (new Xlsx($buku))->save('php://output'),
            $namaBerkas,
            ['Content-Type' => self::MIME]
        );
    }

    /** Nama berkas dari bagian-bagian yang tidak kosong, mis. "kiriman_gudang-pusat_2026-10-01_2026-10-20.xlsx". */
    public static function namaBerkas(string ...$bagian): string
    {
        return collect($bagian)->filter()->map(fn (string $b) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $b) ? $b : Str::slug($b))->implode('_').'.xlsx';
    }

    /**
     * Satu lembar: judul, keterangan, waktu dibuat, tabel Excel (kepala, isi, saringan), dan baris total bila diminta.
     *
     * @param  array<string, ?string>  $kolom  [judul kolom => tipe]
     * @param  Collection<int, array>  $baris
     * @param  list<string>  $jumlahkan  judul kolom yang diberi baris total
     */
    public static function lembar(Spreadsheet $buku, string $nama, string $judul, string $info, array $kolom, Collection $baris, array $jumlahkan = [], string $teksKosong = 'Tidak ada data pada periode ini.'): void
    {
        $lembar = new Worksheet($buku, Str::limit($nama, 31, ''));
        $buku->addSheet($lembar);

        $jumlahKolom = count($kolom);
        $kolomAkhir = Coordinate::stringFromColumnIndex($jumlahKolom);
        $kepala = self::BARIS_KEPALA;
        $barisAwal = $kepala + 1;
        $barisAkhir = $kepala + max($baris->count(), 1);

        $lembar->setCellValue('A1', $judul);
        $lembar->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $lembar->setCellValue('A2', $info);
        $lembar->setCellValue('A3', 'Dibuat '.now()->translatedFormat('j F Y, H.i'));
        $lembar->getStyle('A2:A3')->getFont()->getColor()->setARGB(GayaExcel::WARNA_REDUP);

        // Digabung selebar tabel supaya teks panjang tidak ikut melebarkan kolom pertama.
        foreach ([1, 2, 3] as $r) {
            $lembar->mergeCells("A{$r}:{$kolomAkhir}{$r}");
        }

        $lembar->fromArray(array_keys($kolom), null, 'A'.$kepala);

        if ($baris->isEmpty()) {
            $lembar->setCellValue('A'.$barisAwal, $teksKosong);
            $lembar->getStyle('A'.$barisAwal)->getFont()->setItalic(true)->getColor()->setARGB(GayaExcel::WARNA_REDUP);
        }

        $kolomTipe = array_values($kolom);

        foreach ($baris->values() as $i => $isi) {
            $r = $barisAwal + $i;

            foreach (array_values($isi) as $k => $nilai) {
                $sel = Coordinate::stringFromColumnIndex($k + 1).$r;
                $tipe = $kolomTipe[$k] ?? null;

                if ($nilai instanceof Carbon) {
                    $nilai = TanggalExcel::PHPToExcel($tipe === self::WAKTU ? $nilai : $nilai->copy()->startOfDay());
                } elseif (is_float($nilai)) {
                    $nilai = round($nilai, 3);
                }

                $lembar->setCellValue($sel, $nilai);

                if ($tipe === self::JUMLAH && $nilai !== null) {
                    $lembar->getStyle($sel)->getNumberFormat()->setFormatCode(GayaExcel::formatJumlah($nilai));
                }
            }
        }

        foreach ($kolomTipe as $k => $tipe) {
            $huruf = Coordinate::stringFromColumnIndex($k + 1);
            $format = match ($tipe) {
                self::BULAT   => GayaExcel::FORMAT_BULAT,
                self::PERSEN  => GayaExcel::FORMAT_PERSEN,
                self::TANGGAL => GayaExcel::FORMAT_TANGGAL,
                self::WAKTU   => GayaExcel::FORMAT_WAKTU,
                default       => null,
            };

            if ($format && $baris->isNotEmpty()) {
                $lembar->getStyle("{$huruf}{$barisAwal}:{$huruf}{$barisAkhir}")->getNumberFormat()->setFormatCode($format);
            }

            if (in_array($tipe, [self::JUMLAH, self::BULAT, self::PERSEN], true)) {
                $lembar->getStyle("{$huruf}{$kepala}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }

            $judulKolom = array_keys($kolom)[$k];
            GayaExcel::lebarKolom($lembar, $huruf, $judulKolom, in_array($tipe, [self::TANGGAL, self::WAKTU], true)
                ? [$tipe === self::WAKTU ? '00/00/0000 00:00' : '00/00/0000']
                : $baris->map(fn (array $isi) => array_values($isi)[$k] ?? null));
        }

        GayaExcel::tabel($lembar, $jumlahKolom, $kepala, $barisAkhir);

        if ($jumlahkan !== [] && $baris->isNotEmpty()) {
            $total = $barisAkhir + 1;
            $lembar->setCellValue('A'.$total, 'Total');

            foreach (array_keys($kolom) as $k => $judulKolom) {
                if (in_array($judulKolom, $jumlahkan, true)) {
                    $huruf = Coordinate::stringFromColumnIndex($k + 1);
                    $lembar->setCellValue("{$huruf}{$total}", "=SUM({$huruf}{$barisAwal}:{$huruf}{$barisAkhir})");
                    $jumlah = $baris->sum(fn (array $isi) => (float) (array_values($isi)[$k] ?? 0));
                    $lembar->getStyle("{$huruf}{$total}")->getNumberFormat()->setFormatCode(GayaExcel::formatJumlah(round($jumlah, 3)));
                }
            }

            GayaExcel::barisTotal($lembar, "A{$total}:{$kolomAkhir}{$total}");
        }

        GayaExcel::cetak($lembar, $jumlahKolom, $kepala);
        $lembar->freezePane('A'.$barisAwal);
        $lembar->setSelectedCell('A'.$barisAwal);
    }
}
