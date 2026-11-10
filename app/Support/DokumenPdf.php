<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * Penulis PDF sederhana tanpa pustaka tambahan, untuk dokumen cetak berbentuk tabel
 * (lembar stock opname, surat jalan, daftar permintaan). Kertas A4 tegak, huruf Helvetica bawaan PDF
 * dengan WinAnsiEncoding, jadi teks Indonesia aman (huruf di luar Latin diganti mirip/tanda tanya).
 *
 * Pemakaian: new DokumenPdf(judul, kaki) → judul() → subjudul() / tabel() / paragraf() → tandaTangan() → hasil().
 * Posisi dihitung dari atas halaman (titik/pt); halaman baru dibuat otomatis dan kepala tabel diulang.
 */
final class DokumenPdf
{
    private const LEBAR = 595.28;
    private const TINGGI = 841.89;
    private const TEPI = 40;
    private const RUANG_KAKI = 28;
    /** Jarak antarbaris teks di kolom tabel yang dibungkus, kali ukuran huruf. */
    private const JARAK_BARIS = 1.3;

    /** Lebar huruf ASCII 32–126 per 1000 satuan (metrik standar Helvetica). */
    private const LEBAR_BIASA = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,
        334, 260, 334, 584,
    ];

    /** Lebar huruf ASCII 32–126 per 1000 satuan (metrik standar Helvetica-Bold). */
    private const LEBAR_TEBAL = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        333, 333, 584, 584, 584, 611, 975,
        722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        333, 278, 333, 584, 556, 333,
        556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500,
        389, 280, 389, 584,
    ];

    /** @var list<string> isi setiap halaman yang sudah selesai */
    private array $halaman = [];

    private string $isi = '';

    private float $y = self::TEPI;

    /** @var array{kolom: list<array>, tinggi: float}|null kepala tabel yang sedang berjalan, diulang di halaman baru */
    private ?array $tabelBerjalan = null;

    public function __construct(private string $judulDokumen, private string $kaki = '')
    {
    }

    /** Judul besar di kiri atas dan baris keterangan di bawahnya (lokasi, tanggal cetak, dll.). */
    public function judul(string $teks, array $keterangan = []): self
    {
        $this->pastikanHalaman();
        $this->teks(self::TEPI, $this->y + 16, $teks, 16, true);
        $this->y += 24;

        foreach ($keterangan as $baris) {
            $this->teks(self::TEPI, $this->y + 10, $baris, 9.5, false, 0.35);
            $this->y += 14;
        }

        $this->y += 6;
        $this->garis(self::TEPI, $this->y, self::LEBAR - self::TEPI, $this->y, 0.8, 0.2);
        $this->y += 14;

        return $this;
    }

    public function subjudul(string $teks): self
    {
        $this->pastikanRuang(16 + 24 * 2);
        $this->teks(self::TEPI, $this->y + 11, $teks, 11, true);
        $this->y += 18;

        return $this;
    }

    /** Teks biasa yang dipotong per baris sesuai lebar halaman. */
    public function paragraf(string $teks, float $ukuran = 9.5): self
    {
        foreach ($this->pecahBaris($teks, self::LEBAR - 2 * self::TEPI, $ukuran) as $baris) {
            $this->pastikanRuang($ukuran + 5);
            $this->teks(self::TEPI, $this->y + $ukuran, $baris, $ukuran, false, 0.2);
            $this->y += $ukuran + 5;
        }

        $this->y += 6;

        return $this;
    }

    /**
     * Tabel dengan kepala berlatar abu-abu. Teks yang terlalu panjang dipotong dengan "...", kecuali di kolom
     * bertanda bungkus: teksnya turun ke baris berikutnya dan tinggi baris tabel menyesuaikan.
     *
     * @param  list<array{judul: string, lebar: float, rata?: string, bungkus?: bool}>  $kolom  lebar = bagian dari lebar tabel (jumlah 1);
     *         rata: kiri (bawaan), kanan, tengah
     * @param  iterable<list<string|int|float|null>>  $baris
     */
    public function tabel(array $kolom, iterable $baris, float $tinggiBaris = 20, float $ukuran = 9.5): self
    {
        $lebarTabel = self::LEBAR - 2 * self::TEPI;
        $kolom = array_map(fn (array $k) => $k + ['rata' => 'kiri', 'pt' => $k['lebar'] * $lebarTabel], $kolom);

        $this->tabelBerjalan = ['kolom' => $kolom, 'tinggi' => $tinggiBaris, 'ukuran' => $ukuran];
        $this->pastikanRuang(20 + $tinggiBaris);
        $this->kepalaTabel();

        foreach ($baris as $isi) {
            $isi = array_values($isi);
            $tinggi = $this->tinggiBaris($kolom, $isi, $tinggiBaris, $ukuran);

            if ($this->y + $tinggi > $this->batasBawah()) {
                $this->halamanBaru();
                $this->kepalaTabel();
            }

            $this->barisTabel($kolom, $isi, $tinggi, $ukuran, false);
        }

        $this->tabelBerjalan = null;
        $this->y += 12;

        return $this;
    }

    /** Kotak tanda tangan berjajar di bagian bawah isi. */
    public function tandaTangan(array $label): self
    {
        $this->pastikanRuang(80);
        $this->y += 8;
        $lebar = (self::LEBAR - 2 * self::TEPI) / max(1, count($label));

        foreach (array_values($label) as $i => $teks) {
            $x = self::TEPI + $i * $lebar;
            $this->teks($x + 4, $this->y + 10, $teks, 9.5, false, 0.35);
            $this->garis($x + 4, $this->y + 60, $x + $lebar - 16, $this->y + 60, 0.6, 0.4);
        }

        $this->y += 72;

        return $this;
    }

    /** Isi file PDF. */
    public function hasil(): string
    {
        $this->pastikanHalaman();
        $halaman = [...$this->halaman, $this->isi];
        $jumlah = count($halaman);

        foreach ($halaman as $i => $isi) {
            $kaki = $this->teksKaki($i + 1, $jumlah);
            $halaman[$i] = $isi.$kaki;
        }

        $objek = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Title ('.$this->kode($this->judulDokumen).') /Producer ('.$this->kode(Identitas::aplikasi()).') /CreationDate (D:'.now()->format('YmdHis').') >>',
        ];

        $anak = [];
        $nomor = 6;

        foreach ($halaman as $isi) {
            $anak[] = $nomor.' 0 R';
            $objek[$nomor] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::LEBAR.' '.self::TINGGI.'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($nomor + 1).' 0 R >>';
            $objek[$nomor + 1] = '<< /Length '.strlen($isi)." >>\nstream\n".$isi."\nendstream";
            $nomor += 2;
        }

        $objek[2] = '<< /Type /Pages /Kids ['.implode(' ', $anak).'] /Count '.$jumlah.' >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posisi = [];

        foreach ($objek as $no => $isi) {
            $posisi[$no] = strlen($pdf);
            $pdf .= $no." 0 obj\n".$isi."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objek) + 1)."\n0000000000 65535 f \n";

        foreach ($posisi as $p) {
            $pdf .= str_pad((string) $p, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf."trailer\n<< /Size ".(count($objek) + 1)." /Root 1 0 R /Info 5 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    /** Respons unduhan untuk isi PDF. */
    public static function unduh(string $isi, string $namaFile): Response
    {
        return response($isi, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$namaFile.'"',
        ]);
    }

    /** Lebar teks dalam pt. */
    public static function lebarTeks(string $teks, float $ukuran, bool $tebal = false): float
    {
        $tabel = $tebal ? self::LEBAR_TEBAL : self::LEBAR_BIASA;
        $total = 0;

        foreach (str_split(self::keWinAnsi($teks)) as $huruf) {
            $kode = ord($huruf);
            $total += $kode >= 32 && $kode <= 126 ? $tabel[$kode - 32] : 556;
        }

        return $total * $ukuran / 1000;
    }

    private function kepalaTabel(): void
    {
        $t = $this->tabelBerjalan;
        $this->barisTabel($t['kolom'], array_column($t['kolom'], 'judul'), 20, 9, true);
    }

    /** Tinggi satu baris tabel: tinggi bawaan, atau lebih bila teks di kolom bungkus lebih dari satu baris. */
    private function tinggiBaris(array $kolom, array $isi, float $bawaan, float $ukuran): float
    {
        $baris = 1;

        foreach ($kolom as $i => $k) {
            if ($k['bungkus'] ?? false) {
                $baris = max($baris, count($this->bungkus((string) ($isi[$i] ?? ''), $k['pt'] - 8, $ukuran)));
            }
        }

        return max($bawaan, $baris * $ukuran * self::JARAK_BARIS + 10);
    }

    private function barisTabel(array $kolom, array $isi, float $tinggi, float $ukuran, bool $kepala): void
    {
        $x = self::TEPI;
        $lebarTabel = self::LEBAR - 2 * self::TEPI;

        if ($kepala) {
            $this->isi .= sprintf("0.93 g %.2F %.2F %.2F %.2F re f 0 g\n", $x, self::TINGGI - $this->y - $tinggi, $lebarTabel, $tinggi);
        }

        foreach ($kolom as $i => $k) {
            if (! $kepala && ($k['bungkus'] ?? false)) {
                $daftar = $this->bungkus((string) ($isi[$i] ?? ''), $k['pt'] - 8, $ukuran);
                $atas = $this->y + ($tinggi - count($daftar) * $ukuran * self::JARAK_BARIS) / 2;

                foreach ($daftar as $n => $teks) {
                    $this->teks($x + 4, $atas + ($n + 0.75) * $ukuran * self::JARAK_BARIS, $teks, $ukuran);
                }

                if ($i > 0) {
                    $this->garis($x, $this->y, $x, $this->y + $tinggi, 0.4, 0.8);
                }

                $x += $k['pt'];

                continue;
            }

            $teks = $this->potong((string) ($isi[$i] ?? ''), $k['pt'] - 8, $ukuran, $kepala);
            $lebar = self::lebarTeks($teks, $ukuran, $kepala);
            $tx = match ($k['rata']) {
                'kanan'  => $x + $k['pt'] - 4 - $lebar,
                'tengah' => $x + ($k['pt'] - $lebar) / 2,
                default  => $x + 4,
            };

            $this->teks($tx, $this->y + ($tinggi + $ukuran * 0.7) / 2, $teks, $ukuran, $kepala);

            if ($i > 0) {
                $this->garis($x, $this->y, $x, $this->y + $tinggi, 0.4, 0.8);
            }

            $x += $k['pt'];
        }

        $this->garis(self::TEPI, $this->y + $tinggi, self::LEBAR - self::TEPI, $this->y + $tinggi, 0.4, $kepala ? 0.5 : 0.8);
        $this->y += $tinggi;
    }

    private function teksKaki(int $halaman, int $jumlah): string
    {
        $simpan = $this->isi;
        $this->isi = '';
        $y = self::TINGGI - self::TEPI + 14;
        $kanan = 'Halaman '.$halaman.' dari '.$jumlah;

        $this->garis(self::TEPI, $y - 12, self::LEBAR - self::TEPI, $y - 12, 0.4, 0.8);
        $this->teks(self::TEPI, $y, $this->potong($this->kaki, 360, 8, false), 8, false, 0.45);
        $this->teks(self::LEBAR - self::TEPI - self::lebarTeks($kanan, 8), $y, $kanan, 8, false, 0.45);

        $kaki = $this->isi;
        $this->isi = $simpan;

        return $kaki;
    }

    private function teks(float $x, float $yAtas, string $teks, float $ukuran, bool $tebal = false, float $abu = 0): void
    {
        if ($teks === '') {
            return;
        }

        $this->isi .= sprintf("BT %.2F g /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $abu, $tebal ? 'F2' : 'F1', $ukuran, $x, self::TINGGI - $yAtas, $this->kode($teks));
    }

    private function garis(float $x1, float $y1, float $x2, float $y2, float $tebal, float $abu): void
    {
        $this->isi .= sprintf("%.2F G %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $abu, $tebal, $x1, self::TINGGI - $y1, $x2, self::TINGGI - $y2);
    }

    private function potong(string $teks, float $maks, float $ukuran, bool $tebal): string
    {
        if (self::lebarTeks($teks, $ukuran, $tebal) <= $maks) {
            return $teks;
        }

        while ($teks !== '' && self::lebarTeks($teks.'...', $ukuran, $tebal) > $maks) {
            $teks = mb_substr($teks, 0, -1);
        }

        return rtrim($teks).'...';
    }

    /**
     * Teks untuk kolom bungkus: dipecah per kata, kata yang lebih panjang dari kolom dipecah per huruf.
     *
     * @return list<string>
     */
    private function bungkus(string $teks, float $maks, float $ukuran): array
    {
        $hasil = [];

        foreach ($this->pecahBaris($teks, $maks, $ukuran) as $baris) {
            while (self::lebarTeks($baris, $ukuran) > $maks && mb_strlen($baris) > 1) {
                $n = mb_strlen($baris);

                while ($n > 1 && self::lebarTeks(mb_substr($baris, 0, $n), $ukuran) > $maks) {
                    $n--;
                }

                $hasil[] = mb_substr($baris, 0, $n);
                $baris = mb_substr($baris, $n);
            }

            $hasil[] = $baris;
        }

        return $hasil;
    }

    /** @return list<string> */
    private function pecahBaris(string $teks, float $maks, float $ukuran): array
    {
        $baris = [];
        $sekarang = '';

        foreach (preg_split('/\s+/', trim($teks)) ?: [] as $kata) {
            $coba = $sekarang === '' ? $kata : $sekarang.' '.$kata;

            if ($sekarang !== '' && self::lebarTeks($coba, $ukuran) > $maks) {
                $baris[] = $sekarang;
                $sekarang = $kata;
            } else {
                $sekarang = $coba;
            }
        }

        return $sekarang === '' ? $baris : [...$baris, $sekarang];
    }

    private function pastikanHalaman(): void
    {
        if ($this->isi === '' && $this->halaman === []) {
            $this->y = self::TEPI;
        }
    }

    private function pastikanRuang(float $tinggi): void
    {
        if ($this->y + $tinggi > $this->batasBawah()) {
            $this->halamanBaru();
        }
    }

    private function halamanBaru(): void
    {
        $this->halaman[] = $this->isi;
        $this->isi = '';
        $this->y = self::TEPI;
    }

    private function batasBawah(): float
    {
        return self::TINGGI - self::TEPI - self::RUANG_KAKI;
    }

    private function kode(string $teks): string
    {
        return strtr(self::keWinAnsi($teks), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private static function keWinAnsi(string $teks): string
    {
        $hasil = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $teks);

        return $hasil === false ? preg_replace('/[^\x20-\x7E]/', '?', $teks) : $hasil;
    }
}
