<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\OpnameDetail;
use App\Models\PenjualanDetail;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\BukuExcel;
use App\Support\TautanDokumen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Berkas Excel arsip satu periode buku, dibuat sebelum transaksi dihapus saat tutup buku.
 * Lembar: Ringkasan, Stok Akhir, Masuk & Keluar, Penjualan, Barang Rusak, Kiriman, Opname. Jumlah dalam satuan beli.
 */
class ArsipPeriodeService
{
    /**
     * @param  array<string, int>  $ringkasan  [label => jumlah]
     */
    public function buat(string $nama, ?Carbon $mulai, Carbon $selesai, array $ringkasan, User $oleh): Spreadsheet
    {
        $judul = 'Arsip Periode '.$nama;
        $info = ($mulai ? $mulai->translatedFormat('j F Y, H.i') : 'Awal pemakaian').' sampai '.$selesai->translatedFormat('j F Y, H.i');
        $buku = BukuExcel::baru($judul);

        BukuExcel::lembar($buku, 'Ringkasan', $judul, $info, ['Data' => null, 'Jumlah' => BukuExcel::BULAT],
            collect($ringkasan)->map(fn (int $n, string $label) => [$label, $n])->values());

        $this->stokAkhir($buku, $info);
        $this->mutasi($buku, $info, $oleh);
        $this->penjualan($buku, $info);
        $this->rusak($buku, $info);
        $this->kiriman($buku, $info);
        $this->opname($buku, $info);

        return $buku;
    }

    private function stokAkhir(Spreadsheet $buku, string $info): void
    {
        $lokasi = Cabang::query()->pluck('nama', 'id');
        $bahan = BahanBaku::query()->with(['kategori:id,nama', 'satuanBeli:id,nama'])->get()->keyBy('id');

        $baris = DB::table('stok_saldo')->where('saldo', '!=', 0)->get()
            ->filter(fn ($s) => isset($bahan[$s->bahan_baku_id]))
            ->map(function ($s) use ($lokasi, $bahan) {
                $b = $bahan[$s->bahan_baku_id];

                return [$lokasi[$s->cabang_id] ?? '—', $b->kategori?->nama, $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli((float) $s->saldo)];
            })
            ->sortBy(fn (array $r) => [$r[0], $r[1], $r[2]])
            ->values();

        BukuExcel::lembar($buku, 'Stok Akhir', 'Stok Akhir', $info.' · menjadi stok awal periode berikutnya',
            ['Lokasi' => null, 'Kategori' => null, 'Bahan' => null, 'Satuan' => null, 'Stok' => BukuExcel::JUMLAH],
            $baris, teksKosong: 'Tidak ada stok.');
    }

    private function mutasi(Spreadsheet $buku, string $info, User $oleh): void
    {
        $mutasi = StokMutasi::query()
            ->sah()
            ->with(['cabang:id,nama', 'bahanBaku.satuanBeli:id,nama', 'pembuat:id,nama'])
            ->orderBy('dibuat_pada')
            ->orderBy('id')
            ->get();
        $dokumen = TautanDokumen::untuk($oleh, $mutasi);

        BukuExcel::lembar($buku, 'Masuk & Keluar', 'Masuk & Keluar', $info,
            ['Waktu' => BukuExcel::WAKTU, 'Lokasi' => null, 'Bahan' => null, 'Jenis' => null, 'Dokumen' => null, 'Satuan' => null,
                'Masuk' => BukuExcel::JUMLAH, 'Keluar' => BukuExcel::JUMLAH, 'Oleh' => null],
            $mutasi->map(function (StokMutasi $m) use ($dokumen) {
                $b = $m->bahanBaku;
                $jumlah = $b->keSatuanBeli((float) $m->jumlah);

                return [
                    $m->dibuat_pada->copy(), $m->cabang?->nama, $b->nama, $m->labelJenis(), $dokumen[$m->id]['nomor'] ?? null,
                    $b->satuanBeli->nama, $jumlah > 0 ? $jumlah : null, $jumlah < 0 ? -$jumlah : null, $m->pembuat?->nama ?? 'Sistem',
                ];
            }));
    }

    private function penjualan(Spreadsheet $buku, string $info): void
    {
        $detail = PenjualanDetail::query()
            ->whereHas('penjualanHarian')
            ->with(['penjualanHarian:id,tanggal,cabang_id', 'penjualanHarian.cabang:id,nama', 'menu:id,nama,kategori_menu_id', 'menu.kategoriMenu:id,nama'])
            ->get()
            ->sortBy(fn (PenjualanDetail $d) => [$d->penjualanHarian->tanggal->toDateString(), $d->penjualanHarian->cabang?->nama, $d->menu?->nama])
            ->values();

        BukuExcel::lembar($buku, 'Penjualan', 'Penjualan Harian', $info,
            ['Tanggal' => BukuExcel::TANGGAL, 'Lokasi' => null, 'Kategori Menu' => null, 'Menu' => null, 'Porsi' => BukuExcel::BULAT],
            $detail->map(fn (PenjualanDetail $d) => [
                $d->penjualanHarian->tanggal->copy(), $d->penjualanHarian->cabang?->nama, $d->menu?->kategoriMenu?->nama, $d->menu?->nama, (int) $d->porsi,
            ]),
            ['Porsi']);
    }

    private function rusak(Spreadsheet $buku, string $info): void
    {
        $detail = BarangKeluarDetail::query()
            ->whereHas('barangKeluar', fn (Builder $q) => $q->rusak())
            ->with(['barangKeluar.cabang:id,nama', 'barangKeluar.pencatat:id,nama', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (BarangKeluarDetail $d) => [$d->barangKeluar->tanggal->toDateString(), $d->barangKeluar->nomor, $d->id])
            ->values();

        BukuExcel::lembar($buku, 'Barang Rusak', 'Barang Rusak', $info,
            ['Tanggal' => BukuExcel::TANGGAL, 'Lokasi' => null, 'Nomor' => null, 'Bahan' => null, 'Satuan' => null, 'Jumlah' => BukuExcel::JUMLAH,
                'Alasan' => null, 'Status' => null, 'Dicatat oleh' => null],
            $detail->map(fn (BarangKeluarDetail $d) => [
                $d->barangKeluar->tanggal->copy(), $d->barangKeluar->cabang?->nama, $d->barangKeluar->nomor, $d->bahanBaku->nama,
                $d->bahanBaku->satuanBeli->nama, $d->bahanBaku->keSatuanBeli((float) $d->qty), $d->penjelasan,
                $d->barangKeluar->label_status, $d->barangKeluar->pencatat?->nama,
            ]));
    }

    private function kiriman(Spreadsheet $buku, string $info): void
    {
        $detail = KirimanDetail::query()
            ->whereHas('kiriman', fn (Builder $q) => $q->where('status', '!=', Kiriman::STATUS_DISIAPKAN))
            ->with(['kiriman.keCabang:id,nama', 'kiriman.dariCabang:id,nama', 'kiriman.permintaan:id,nomor', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (KirimanDetail $d) => [$d->kiriman->dikirim_pada?->timestamp, $d->kiriman->nomor, $d->id])
            ->values();

        BukuExcel::lembar($buku, 'Kiriman', 'Kiriman', $info,
            ['Dikirim' => BukuExcel::WAKTU, 'Nomor' => null, 'Dari' => null, 'Tujuan' => null, 'Permintaan' => null, 'Bahan' => null,
                'Satuan' => null, 'Dikirim (Jumlah)' => BukuExcel::JUMLAH, 'Diterima' => BukuExcel::JUMLAH, 'Selisih' => BukuExcel::JUMLAH, 'Status' => null],
            $detail->map(function (KirimanDetail $d) {
                $k = $d->kiriman;
                $b = $d->bahanBaku;
                $diterima = $d->sudahDichecklist();

                return [
                    $k->dikirim_pada?->copy(), $k->nomor, $k->dariCabang?->nama, $k->keCabang?->nama, $k->permintaan?->nomor,
                    $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli((float) $d->qty_kirim),
                    $diterima ? $b->keSatuanBeli((float) $d->qty_terima) : null,
                    $diterima ? $b->keSatuanBeli((float) $d->qty_terima - (float) $d->qty_kirim) : null,
                    $k->tampilanStatus()[0],
                ];
            }));
    }

    private function opname(Spreadsheet $buku, string $info): void
    {
        $detail = OpnameDetail::query()
            ->whereHas('opname')
            ->with(['opname.cabang:id,nama', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (OpnameDetail $d) => [$d->opname->tanggal?->toDateString(), $d->opname->nomor, $d->id])
            ->values();

        BukuExcel::lembar($buku, 'Opname', 'Stock Opname', $info,
            ['Tanggal' => BukuExcel::TANGGAL, 'Lokasi' => null, 'Nomor' => null, 'Bahan' => null, 'Satuan' => null,
                'Stok Sistem' => BukuExcel::JUMLAH, 'Hitung Fisik' => BukuExcel::JUMLAH, 'Selisih' => BukuExcel::JUMLAH, 'Status' => null],
            $detail->map(function (OpnameDetail $d) {
                $b = $d->bahanBaku;
                $fisik = $d->qty_fisik !== null ? $b->keSatuanBeli((float) $d->qty_fisik) : null;
                $sistem = $b->keSatuanBeli((float) $d->qty_sistem);

                return [
                    $d->opname->tanggal?->copy(), $d->opname->cabang?->nama, $d->opname->nomor, $b->nama, $b->satuanBeli->nama,
                    $sistem, $fisik, $fisik !== null ? $fisik - $sistem : null, $d->opname->tampilanStatus()[0],
                ];
            }));
    }
}
