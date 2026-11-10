<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\User;
use App\Support\Angka;
use App\Support\DokumenPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lembar stock opname PDF untuk dicetak dan diisi tangan: per kategori, berisi nama bahan, stok sistem,
 * dan satuan (satuan beli), dengan kolom kosong untuk hasil hitung fisik dan catatan.
 */
class LembarOpnameService
{
    private const KOLOM = [
        ['judul' => 'No', 'lebar' => 0.06, 'rata' => 'kanan'],
        ['judul' => 'Nama Bahan', 'lebar' => 0.36],
        ['judul' => 'Stok Sistem', 'lebar' => 0.14, 'rata' => 'kanan'],
        ['judul' => 'Satuan', 'lebar' => 0.12],
        ['judul' => 'Hitung Fisik', 'lebar' => 0.14],
        ['judul' => 'Catatan', 'lebar' => 0.18],
    ];

    public function buat(Cabang $lokasi, ?Kategori $kategori, User $pencetak): string
    {
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->pluck('saldo', 'bahan_baku_id');

        $kelompok = BahanBaku::query()
            ->aktif()
            ->when($kategori, fn ($q) => $q->where('kategori_id', $kategori->id))
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama'])
            ->orderBy('nama')
            ->get()
            ->groupBy('kategori_id')
            ->sortBy(fn ($daftar) => [$daftar->first()->kategori->urutan, $daftar->first()->kategori->nama]);

        $pdf = new DokumenPdf(
            'Lembar stock opname '.$lokasi->nama,
            'Lembar stock opname · '.$lokasi->nama.' · dicetak '.now()->translatedFormat('j M Y H.i')
        );

        $pdf->judul('Lembar Stock Opname', [
            $lokasi->nama.' · Kategori: '.($kategori?->nama ?? 'Semua'),
            'Dicetak '.now()->translatedFormat('l, j F Y H.i').' oleh '.$pencetak->nama,
            'Stok sistem dalam satuan beli. Tulis hasil hitung fisik di kolom kosong, lalu masukkan ke menu Stock opname.',
        ]);

        if ($kelompok->isEmpty()) {
            $pdf->paragraf('Belum ada bahan baku aktif'.($kategori ? ' di kategori ini.' : '.'));
        }

        foreach ($kelompok as $daftar) {
            $pdf->subjudul($daftar->first()->kategori->nama.' ('.$daftar->count().' bahan)');
            $pdf->tabel(self::KOLOM, $daftar->values()->map(fn (BahanBaku $b, int $i) => [
                $i + 1,
                $b->nama,
                Angka::tampil($b->keSatuanBeli((float) ($saldo[$b->id] ?? 0))),
                $b->satuanBeli->nama,
                '',
                '',
            ]), 22);
        }

        $pdf->tandaTangan(['Dihitung oleh', 'Diperiksa oleh']);

        return $pdf->hasil();
    }

    public function namaFile(Cabang $lokasi, ?Kategori $kategori): string
    {
        return Str::slug('lembar opname '.$lokasi->kode.' '.($kategori?->nama ?? 'semua').' '.now()->format('Y-m-d')).'.pdf';
    }
}
