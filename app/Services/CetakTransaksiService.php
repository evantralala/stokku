<?php

namespace App\Services;

use App\Support\Identitas;
use App\Models\BarangKeluar;
use App\Models\BarangKeluarDetail;
use App\Models\BarangMasuk;
use App\Models\BarangMasukDetail;
use App\Models\Opname;
use App\Models\OpnameDetail;
use App\Models\Persetujuan;
use App\Models\User;
use App\Support\Angka;
use App\Support\DokumenPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * PDF bukti transaksi gudang: bukti barang masuk, berita acara barang rusak, dan hasil stock opname.
 * Jumlah ditampilkan dalam satuan beli. Keputusan Admin Utama terakhir ikut dicetak.
 */
class CetakTransaksiService
{
    private const KOLOM_BARANG_MASUK = [
        ['judul' => 'No', 'lebar' => 0.06, 'rata' => 'kanan'],
        ['judul' => 'Nama Barang', 'lebar' => 0.40],
        ['judul' => 'Jumlah', 'lebar' => 0.14, 'rata' => 'kanan'],
        ['judul' => 'Satuan', 'lebar' => 0.12],
        ['judul' => 'Keterangan', 'lebar' => 0.28],
    ];

    private const KOLOM_BARANG_RUSAK = [
        ['judul' => 'No', 'lebar' => 0.05, 'rata' => 'kanan'],
        ['judul' => 'Nama Barang', 'lebar' => 0.30],
        ['judul' => 'Jumlah', 'lebar' => 0.11, 'rata' => 'kanan'],
        ['judul' => 'Satuan', 'lebar' => 0.10],
        ['judul' => 'Alasan', 'lebar' => 0.44, 'bungkus' => true],
    ];

    private const KOLOM_OPNAME_LENGKAP = [
        ['judul' => 'No', 'lebar' => 0.06, 'rata' => 'kanan'],
        ['judul' => 'Nama Barang', 'lebar' => 0.28],
        ['judul' => 'Satuan', 'lebar' => 0.10],
        ['judul' => 'Sistem', 'lebar' => 0.12, 'rata' => 'kanan'],
        ['judul' => 'Fisik', 'lebar' => 0.12, 'rata' => 'kanan'],
        ['judul' => 'Selisih', 'lebar' => 0.12, 'rata' => 'kanan'],
        ['judul' => 'Catatan', 'lebar' => 0.20],
    ];

    private const KOLOM_OPNAME_FISIK = [
        ['judul' => 'No', 'lebar' => 0.06, 'rata' => 'kanan'],
        ['judul' => 'Nama Barang', 'lebar' => 0.40],
        ['judul' => 'Satuan', 'lebar' => 0.12],
        ['judul' => 'Hitung Fisik', 'lebar' => 0.16, 'rata' => 'kanan'],
        ['judul' => 'Catatan', 'lebar' => 0.26],
    ];

    public function barangMasuk(BarangMasuk $dokumen, User $pencetak): string
    {
        $dokumen->loadMissing(['cabang:id,nama', 'pencatat:id,nama', 'detail.bahanBaku.satuanBeli', 'persetujuan.oleh:id,nama']);

        $pdf = new DokumenPdf('Bukti barang masuk '.$dokumen->nomor, $this->kaki('Bukti barang masuk '.$dokumen->nomor));
        $pdf->judul('Bukti Barang Masuk', $this->keterangan(
            $dokumen->nomor.' · '.$dokumen->cabang->nama,
            'Tanggal: '.$dokumen->tanggal->translatedFormat('j F Y').' · dicatat oleh '.($dokumen->pencatat?->nama ?? '-'),
            BarangMasuk::TAMPILAN_STATUS[$dokumen->status][0] ?? $dokumen->status,
            $dokumen->persetujuan,
            $pencetak,
        ));

        $pdf->tabel(self::KOLOM_BARANG_MASUK, $dokumen->detail->sortBy('id')->values()->map(fn (BarangMasukDetail $d, int $i) => [
            $i + 1,
            $d->bahanBaku->nama,
            Angka::tampil($d->bahanBaku->keSatuanBeli($d->qty)),
            $d->bahanBaku->satuanBeli->nama,
            $d->keterangan ?? '',
        ]), 22);

        if (filled($dokumen->catatan)) {
            $pdf->paragraf('Catatan: '.$dokumen->catatan);
        }

        $pdf->tandaTangan(['Diterima (Gudang Pusat)', 'Diperiksa', 'Disetujui (Admin Utama)']);

        return $pdf->hasil();
    }

    public function barangRusak(BarangKeluar $dokumen, User $pencetak): string
    {
        $dokumen->loadMissing(['cabang:id,nama', 'pencatat:id,nama', 'detail.bahanBaku.satuanBeli', 'persetujuan.oleh:id,nama']);

        $pdf = new DokumenPdf('Berita acara barang rusak '.$dokumen->nomor, $this->kaki('Berita acara barang rusak '.$dokumen->nomor));
        $pdf->judul('Berita Acara Barang Rusak', $this->keterangan(
            $dokumen->nomor.' · '.$dokumen->cabang->nama,
            'Tanggal: '.$dokumen->tanggal->translatedFormat('j F Y').' · dilaporkan oleh '.($dokumen->pencatat?->nama ?? '-'),
            $dokumen->label_status,
            $dokumen->persetujuan,
            $pencetak,
        ));

        $pdf->paragraf('Barang berikut rusak atau terbuang dan dikeluarkan dari stok '.$dokumen->cabang->nama.' setelah disetujui Admin Utama.', 9);

        $pdf->tabel(self::KOLOM_BARANG_RUSAK, $dokumen->detail->sortBy('id')->values()->map(fn (BarangKeluarDetail $d, int $i) => [
            $i + 1,
            $d->bahanBaku->nama,
            Angka::tampil($d->bahanBaku->keSatuanBeli($d->qty)),
            $d->bahanBaku->satuanBeli->nama,
            $d->penjelasan ?? '',
        ]), 22);

        $pdf->tandaTangan(['Pelapor', 'Disetujui (Admin Utama)']);

        return $pdf->hasil();
    }

    /**
     * Hasil stock opname. Stok sistem dan selisih hanya dicetak bila $lengkap (Admin Utama, atau opname yang sudah
     * disetujui), supaya hitung buta tetap terjaga selama opname berjalan.
     */
    public function opname(Opname $opname, User $pencetak, bool $lengkap): string
    {
        $opname->loadMissing(['cabang:id,nama', 'petugas:id,nama', 'persetujuan.oleh:id,nama']);
        $detail = $opname->detail()
            ->with(['bahanBaku:id,nama,kategori_id,satuan_beli_id,isi_per_satuan_beli', 'bahanBaku.satuanBeli:id,nama', 'bahanBaku.kategori:id,nama,urutan'])
            ->get()
            ->sortBy(fn (OpnameDetail $d) => [$d->bahanBaku->kategori->urutan, $d->bahanBaku->kategori->nama, $d->bahanBaku->nama])
            ->values();

        $pdf = new DokumenPdf('Hasil stock opname '.$opname->nomor, $this->kaki('Hasil stock opname '.$opname->nomor));
        $pdf->judul('Hasil Stock Opname', $this->keterangan(
            $opname->nomor.' · '.$opname->cabang->nama,
            'Tanggal: '.$opname->tanggal->translatedFormat('j F Y').' · petugas '.($opname->petugas?->nama ?? '-'),
            Opname::TAMPILAN_STATUS[$opname->status][0] ?? $opname->status,
            $opname->persetujuan,
            $pencetak,
        ));

        $dihitung = $detail->whereNotNull('qty_fisik');
        $ringkas = $dihitung->count().' dari '.$detail->count().' bahan dihitung';

        if ($lengkap) {
            $kurang = $dihitung->filter(fn (OpnameDetail $d) => (float) $d->qty_fisik < (float) $d->qty_sistem)->count();
            $lebih = $dihitung->filter(fn (OpnameDetail $d) => (float) $d->qty_fisik > (float) $d->qty_sistem)->count();
            $ringkas .= " · {$kurang} kurang dari sistem · {$lebih} lebih dari sistem";
        }

        $pdf->paragraf($ringkas.'.', 9);

        foreach ($detail->groupBy(fn (OpnameDetail $d) => $d->bahanBaku->kategori->nama) as $kategori => $baris) {
            $pdf->subjudul($kategori);
            $pdf->tabel(
                $lengkap ? self::KOLOM_OPNAME_LENGKAP : self::KOLOM_OPNAME_FISIK,
                $baris->values()->map(fn (OpnameDetail $d, int $i) => $this->barisOpname($d, $i + 1, $lengkap)),
                20
            );
        }

        $pdf->tandaTangan(['Petugas hitung', 'Penanggung jawab lokasi', 'Disetujui (Admin Utama)']);

        return $pdf->hasil();
    }

    public function namaFile(string $jenis, string $nomor): string
    {
        return Str::slug($jenis.' '.$nomor).'.pdf';
    }

    private function barisOpname(OpnameDetail $d, int $no, bool $lengkap): array
    {
        $b = $d->bahanBaku;
        $fisik = $d->qty_fisik === null ? null : $b->keSatuanBeli($d->qty_fisik);

        if (! $lengkap) {
            return [$no, $b->nama, $b->satuanBeli->nama, $fisik === null ? '-' : Angka::tampil($fisik), $d->catatan ?? ''];
        }

        $sistem = $b->keSatuanBeli($d->qty_sistem);
        $selisih = $fisik === null ? null : $fisik - $sistem;

        return [
            $no, $b->nama, $b->satuanBeli->nama, Angka::tampil($sistem),
            $fisik === null ? '-' : Angka::tampil($fisik),
            $selisih === null ? '-' : ($selisih > 0 ? '+' : '').Angka::tampil($selisih),
            $d->catatan ?? '',
        ];
    }

    /**
     * Baris keterangan di bawah judul: identitas, tanggal, status, keputusan Admin Utama terakhir, dan pencetak.
     *
     * @param  Collection<int, Persetujuan>  $persetujuan
     */
    private function keterangan(string $identitas, string $tanggal, string $status, Collection $persetujuan, User $pencetak): array
    {
        $keputusan = $persetujuan->sortByDesc('pada')->first();

        return array_values(array_filter([
            $identitas,
            $tanggal,
            'Status: '.$status.($keputusan ? $this->teksKeputusan($keputusan, $status) : ''),
            $keputusan && filled($keputusan->catatan) ? 'Catatan keputusan: '.$keputusan->catatan : null,
            'Dicetak '.$this->waktu(now()).' oleh '.$pencetak->nama,
        ]));
    }

    /** ", oleh X, 17 Okt 2026, 19.45" bila keputusan sama dengan status; selain itu disebut keputusannya. */
    private function teksKeputusan(Persetujuan $keputusan, string $status): string
    {
        $label = Persetujuan::LABEL_KEPUTUSAN[$keputusan->keputusan] ?? $keputusan->keputusan;
        $oleh = ' oleh '.($keputusan->oleh?->nama ?? 'sistem').', '.$this->waktu($keputusan->pada);

        return $label === $status ? $oleh : ' · keputusan terakhir: '.$label.$oleh;
    }

    private function kaki(string $judul): string
    {
        return Identitas::singkat().' · '.$judul.' · dicetak '.now()->translatedFormat('j M Y H.i');
    }

    private function waktu($waktu): string
    {
        return $waktu->translatedFormat('j M Y, H.i');
    }
}
