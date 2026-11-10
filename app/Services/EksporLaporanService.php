<?php

namespace App\Services;

use App\Models\KirimanDetail;
use App\Support\BukuExcel;
use App\Support\Periode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Mengubah data laporan (dari LaporanService, data yang sama dengan halaman) menjadi berkas Excel.
 * Jumlah ditulis sebagai angka dalam satuan beli supaya bisa langsung dijumlahkan di Excel.
 */
class EksporLaporanService
{
    private const JUMLAH = BukuExcel::JUMLAH;
    private const BULAT = BukuExcel::BULAT;
    private const PERSEN = BukuExcel::PERSEN;
    private const TANGGAL = BukuExcel::TANGGAL;

    /**
     * @param  array  $data  data laporan seperti yang dikirim ke tampilan
     */
    public function buat(string $jenis, string $judul, Periode $periode, ?string $namaLokasi, array $data): Spreadsheet
    {
        $buku = BukuExcel::baru($judul);

        $info = 'Periode: '.$periode->label()
            .($namaLokasi !== null ? ' · Lokasi: '.$namaLokasi : '');

        match ($jenis) {
            'stok'      => $this->stok($buku, $judul, $info, $data),
            'penjualan' => $this->penjualan($buku, $judul, $info, $data),
            'kategori'  => $this->kategori($buku, $judul, $info, $data),
            'selisih'   => $this->selisih($buku, $judul, $info, $data),
            'rusak'     => $this->rusak($buku, $judul, $info, $data),
        };

        $buku->setActiveSheetIndex(0);

        return $buku;
    }

    /** Nama berkas, mis. "laporan-rekap-stok_gudang-pusat_2026-10-01_2026-10-20.xlsx". */
    public static function namaBerkas(string $judul, Periode $periode, ?string $namaLokasi): string
    {
        return collect(['laporan-'.Str::slug($judul), $namaLokasi ? Str::slug($namaLokasi) : null, $periode->dariTanggal(), $periode->sampaiTanggal()])
            ->filter()
            ->implode('_').'.xlsx';
    }

    private function stok(Spreadsheet $buku, string $judul, string $info, array $data): void
    {
        $kolom = ['Bahan' => null, 'Kategori' => null, 'Satuan' => null, 'Awal' => self::JUMLAH];
        foreach ($data['kolom'] as $judulKolom) {
            $kolom[$judulKolom] = self::JUMLAH;
        }
        $kolom['Akhir'] = self::JUMLAH;

        $baris = $data['baris']->map(function (array $r) use ($data) {
            $b = $r['bahan'];
            $isi = (float) $b->isi_per_satuan_beli;

            return [
                $b->nama, $b->kategori->nama, $b->satuanBeli->nama, $r['awal'] / $isi,
                ...collect(array_keys($data['kolom']))->map(fn ($j) => $r['jenis'][$j] / $isi)->all(),
                $r['akhir'] / $isi,
            ];
        });

        BukuExcel::lembar($buku, 'Rekap Stok', $judul, $info, $kolom, $baris);
    }

    private function penjualan(Spreadsheet $buku, string $judul, string $info, array $data): void
    {
        BukuExcel::lembar($buku, 'Penjualan Menu', $judul, $info,
            ['Kategori' => null, 'Menu' => null, 'Porsi' => self::BULAT],
            $data['menu']->map(fn ($m) => [$m->kategori, $m->nama, $m->porsi]),
            jumlahkan: ['Porsi']
        );

        BukuExcel::lembar($buku, 'Pemakaian Bahan', $judul, $info,
            ['Kategori' => null, 'Bahan' => null, 'Satuan' => null, 'Pemakaian Resep' => self::JUMLAH, 'Rusak' => self::JUMLAH,
                'Hilang (Opname)' => self::JUMLAH, 'Lebih (Opname)' => self::JUMLAH, 'Hilang / Pemakaian' => self::PERSEN],
            $data['bahan']->map(function (array $r) {
                $b = $r['bahan'];
                $isi = (float) $b->isi_per_satuan_beli;

                return [
                    $b->kategori->nama, $b->nama, $b->satuanBeli->nama, $r['pemakaian'] / $isi, $r['rusak'] / $isi,
                    $r['hilang'] / $isi, $r['lebih'] / $isi,
                    $r['persen_hilang'] === null ? null : $r['persen_hilang'] / 100,
                ];
            })
        );
    }

    private function kategori(Spreadsheet $buku, string $judul, string $info, array $data): void
    {
        BukuExcel::lembar($buku, 'Penjualan per Kategori', $judul, $info,
            ['Kategori Menu' => null, 'Porsi' => self::BULAT, 'Bagian' => self::PERSEN, 'Menu Terjual' => self::BULAT, 'Terlaris' => null, 'Porsi Terlaris' => self::BULAT],
            $data['perMenu']->map(fn (array $r) => [
                $r['kategori'], $r['porsi'], $r['persen'] / 100, $r['menu'], $r['terlaris']?->nama, $r['terlaris']?->porsi,
            ]),
            jumlahkan: ['Porsi']
        );

        BukuExcel::lembar($buku, 'Bahan per Kategori', $judul, $info,
            ['Kategori Bahan' => null, 'Bahan Terpakai' => self::BULAT, 'Ada yang Rusak' => self::BULAT,
                'Hilang Saat Opname' => self::BULAT, 'Lewat Toleransi' => self::BULAT],
            $data['perBahan']->map(fn (array $r) => [$r['kategori'], $r['dipakai'], $r['rusak'], $r['hilang'], $r['lewat_toleransi']])
        );
    }

    private function selisih(Spreadsheet $buku, string $judul, string $info, array $data): void
    {
        BukuExcel::lembar($buku, 'Selisih Kiriman', $judul, $info,
            ['Tanggal' => self::TANGGAL, 'Nomor' => null, 'Lokasi' => null, 'Bahan' => null, 'Satuan' => null,
                'Dikirim' => self::JUMLAH, 'Diterima' => self::JUMLAH, 'Selisih' => self::JUMLAH, 'Kondisi' => null, 'Catatan' => null],
            $data['kiriman']->map(fn ($r) => [
                Carbon::parse($r->waktu), $r->nomor, $r->lokasi, $r->bahan, $r->satuan,
                $r->kirim / $r->isi, $r->terima / $r->isi, ($r->terima - $r->kirim) / $r->isi,
                KirimanDetail::LABEL_KONDISI[$r->kondisi] ?? $r->kondisi, $r->catatan,
            ])
        );

        BukuExcel::lembar($buku, 'Selisih Opname', $judul, $info,
            ['Tanggal' => self::TANGGAL, 'Nomor' => null, 'Lokasi' => null, 'Bahan' => null, 'Satuan' => null,
                'Stok Sistem' => self::JUMLAH, 'Hitung Fisik' => self::JUMLAH, 'Selisih' => self::JUMLAH, 'Catatan' => null],
            $data['opname']->map(fn ($r) => [
                Carbon::parse($r->waktu), $r->nomor, $r->lokasi, $r->bahan, $r->satuan,
                $r->sistem / $r->isi, $r->fisik / $r->isi, ($r->fisik - $r->sistem) / $r->isi, $r->catatan,
            ])
        );
    }

    private function rusak(Spreadsheet $buku, string $judul, string $info, array $data): void
    {
        BukuExcel::lembar($buku, 'Per Bahan', $judul, $info,
            ['Bahan' => null, 'Satuan' => null, 'Berapa Kali' => self::BULAT, 'Jumlah Terbuang' => self::JUMLAH],
            $data['per_bahan']->map(fn ($r) => [$r->bahan, $r->satuan, (int) $r->kali, $r->qty / $r->isi])
        );
    }
}
