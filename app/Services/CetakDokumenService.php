<?php

namespace App\Services;

use App\Support\Identitas;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\User;
use App\Support\Angka;
use App\Support\DokumenPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * PDF dokumen pengiriman: permintaan barang (semua barang beserta statusnya: menunggu, disetujui, dikirim,
 * diterima, ditolak) dan surat jalan kiriman yang dibawa bersama barang ke cabang.
 * Tabel keduanya memakai susunan yang sama: No, Nama barang, Jumlah, Satuan, lalu kolom isian/status.
 * Jumlah ditampilkan dalam satuan beli.
 */
class CetakDokumenService
{
    private const KOLOM_PERMINTAAN = [
        ['judul' => 'No', 'lebar' => 0.07, 'rata' => 'kanan'],
        ['judul' => 'Nama Barang', 'lebar' => 0.38],
        ['judul' => 'Jumlah', 'lebar' => 0.16, 'rata' => 'kanan'],
        ['judul' => 'Satuan', 'lebar' => 0.14],
        ['judul' => 'Status', 'lebar' => 0.25],
    ];

    private const KOLOM_SURAT_JALAN = [
        ['judul' => 'No', 'lebar' => 0.07, 'rata' => 'kanan'],
        ['judul' => 'Nama Barang', 'lebar' => 0.43],
        ['judul' => 'Jumlah', 'lebar' => 0.16, 'rata' => 'kanan'],
        ['judul' => 'Satuan', 'lebar' => 0.14],
        ['judul' => 'Diterima', 'lebar' => 0.20],
    ];

    /** Satu permintaan: semua barang yang diminta beserta statusnya. */
    public function permintaan(Permintaan $permintaan, User $pencetak): string
    {
        $permintaan->loadMissing('cabang:id,nama');
        $pdf = new DokumenPdf('Permintaan barang '.$permintaan->nomor, $this->kaki('Permintaan barang '.$permintaan->nomor));

        $pdf->judul('Permintaan Barang', [
            $permintaan->nomor.' · '.$permintaan->cabang->nama,
            'Dicetak '.$this->waktu(now()).' oleh '.$pencetak->nama,
        ]);

        $this->isiPermintaan($pdf, $permintaan, false);
        $pdf->tandaTangan(['Diajukan (cabang)', 'Disetujui (Admin Utama)', 'Disiapkan (Gudang Pusat)']);

        return $pdf->hasil();
    }

    /**
     * Permintaan yang masih punya barang disetujui dan belum terkirim penuh, satu bagian per permintaan
     * (setiap bagian tetap memuat semua barang beserta statusnya).
     *
     * @param  Collection<int, Permintaan>  $daftar
     */
    public function daftarPermintaan(Collection $daftar, User $pencetak): string
    {
        $pdf = new DokumenPdf('Daftar permintaan siap kirim', $this->kaki('Daftar permintaan siap kirim'));

        $pdf->judul('Daftar Permintaan Siap Kirim', [
            $daftar->count().' permintaan belum terkirim penuh',
            'Dicetak '.$this->waktu(now()).' oleh '.$pencetak->nama,
        ]);

        if ($daftar->isEmpty()) {
            $pdf->paragraf('Semua permintaan yang disetujui sudah dikirim.');
        }

        foreach ($daftar as $permintaan) {
            $this->isiPermintaan($pdf, $permintaan, true);
        }

        return $pdf->hasil();
    }

    /** Surat jalan satu kiriman. */
    public function suratJalan(Kiriman $kiriman, User $pencetak): string
    {
        $kiriman->loadMissing(['dariCabang:id,nama', 'keCabang:id,nama', 'pengirim:id,nama', 'permintaan:id,nomor', 'detail.bahanBaku.satuanBeli']);

        $pdf = new DokumenPdf('Surat jalan '.$kiriman->nomor, $this->kaki('Surat jalan '.$kiriman->nomor));

        $pdf->judul('Surat Jalan', array_values(array_filter([
            $kiriman->nomor.($kiriman->permintaan ? ' · permintaan '.$kiriman->permintaan->nomor : ''),
            'Tanggal kirim: '.($kiriman->dikirim_pada ? $this->waktu($kiriman->dikirim_pada) : '-'),
            'Dari '.$kiriman->dariCabang->nama.' ke '.$kiriman->keCabang->nama,
            'Dicetak '.$this->waktu(now()).' oleh '.$pencetak->nama,
        ])));

        $pdf->tabel(self::KOLOM_SURAT_JALAN, $kiriman->detail->sortBy('id')->values()->map(fn (KirimanDetail $d, int $i) => [
            $i + 1,
            $d->bahanBaku->nama,
            Angka::tampil($d->bahanBaku->keSatuanBeli($d->qty_kirim)),
            $d->bahanBaku->satuanBeli->nama,
            '',
        ]), 22);

        if (filled($kiriman->catatan_pusat)) {
            $pdf->paragraf('Catatan: '.$kiriman->catatan_pusat);
        }

        $pdf->tandaTangan(['Pengirim'.($kiriman->pengirim ? ' ('.$kiriman->pengirim->nama.')' : ''), 'Sopir / kurir', 'Penerima']);

        return $pdf->hasil();
    }

    public function namaFile(string $jenis, string $nomor = ''): string
    {
        return Str::slug($jenis.' '.$nomor.' '.now()->format('Y-m-d')).'.pdf';
    }

    private function isiPermintaan(DokumenPdf $pdf, Permintaan $permintaan, bool $denganJudul): void
    {
        $permintaan->loadMissing([
            'cabang:id,nama', 'pengaju:id,nama', 'detail.bahanBaku.satuanBeli', 'detail.persetujuan.oleh:id,nama',
            'kiriman:id,permintaan_id,status', 'kiriman.detail:id,kiriman_id,bahan_baku_id,qty_terima',
        ]);

        $detail = $permintaan->detail->sortBy(fn (PermintaanDetail $d) => $d->bahanBaku->nama)->values();
        $diterima = $permintaan->kiriman
            ->whereIn('status', [Kiriman::STATUS_DICHECKLIST, Kiriman::STATUS_SELESAI])
            ->flatMap(fn (Kiriman $k) => $k->detail)
            ->groupBy('bahan_baku_id')
            ->map(fn (Collection $d) => round((float) $d->sum('qty_terima'), 3));

        $keputusan = $detail->flatMap(fn (PermintaanDetail $d) => $d->persetujuan)
            ->sortByDesc('pada')
            ->first();

        if ($denganJudul) {
            $pdf->subjudul($permintaan->cabang->nama.' · '.$permintaan->nomor);
        }

        $pdf->paragraf(collect([
            'Diajukan '.$this->waktu($permintaan->diajukan_pada).($permintaan->otomatis() ? ' (otomatis)' : ' oleh '.($permintaan->pengaju?->nama ?? '-')),
            $keputusan ? 'diputuskan '.$this->waktu($keputusan->pada).' oleh '.($keputusan->oleh?->nama ?? 'sistem') : null,
            $permintaan->status === Permintaan::STATUS_BATAL ? 'dibatalkan cabang' : null,
        ])->filter()->implode(' · '), 9);

        $jumlahStatus = $detail->countBy('status');
        $pdf->paragraf('Status: '.collect(PermintaanDetail::TAMPILAN_STATUS)
            ->filter(fn (array $t, string $status) => isset($jumlahStatus[$status]))
            ->map(fn (array $t, string $status) => $jumlahStatus[$status].' '.mb_strtolower($t[0]))
            ->implode(', ').'.', 9);

        $pdf->tabel(self::KOLOM_PERMINTAAN, $detail->map(fn (PermintaanDetail $d, int $i) => [
            $i + 1,
            $d->bahanBaku->nama,
            Angka::tampil($d->bahanBaku->keSatuanBeli($d->qty_diminta)),
            $d->bahanBaku->satuanBeli->nama,
            $this->statusBarang($d, $diterima[$d->bahan_baku_id] ?? 0.0),
        ]), 22);
    }

    /** Status satu barang permintaan, mis. "Disetujui 4", "Dikirim 2 dari 4", "Diterima 4", "Ditolak". */
    private function statusBarang(PermintaanDetail $d, float $diterima): string
    {
        if ($d->status !== PermintaanDetail::STATUS_DISETUJUI) {
            return $d->tampilanStatus()[0];
        }

        $b = $d->bahanBaku;
        $setuju = (float) $d->qty_disetujui;
        $dari = fn (float $qty) => Angka::tampil($b->keSatuanBeli($qty)).($qty + 0.0005 < $setuju ? ' dari '.Angka::tampil($b->keSatuanBeli($setuju)) : '');

        return match (true) {
            $diterima > 0               => 'Diterima '.$dari($diterima),
            (float) $d->qty_dikirim > 0 => 'Dikirim '.$dari((float) $d->qty_dikirim),
            default                     => 'Disetujui '.Angka::tampil($b->keSatuanBeli($setuju)),
        };
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
