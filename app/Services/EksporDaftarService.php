<?php

namespace App\Services;

use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\Opname;
use App\Models\OpnameDetail;
use App\Models\PenjualanDetail;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\Impor\ImporPenyesuaianStokService;
use App\Support\Angka;
use App\Support\BukuExcel;
use App\Support\ContohTemplat;
use App\Support\HariOperasional;
use App\Support\Periode;
use App\Support\TautanDokumen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Excel dari halaman daftar: stok saat ini, riwayat stok, barang rusak, kiriman, permintaan, penjualan harian, log, dan hasil opname.
 * Jumlah ditulis sebagai angka dalam satuan beli; satu baris per barang supaya mudah disaring dan dijumlahkan di Excel.
 */
class EksporDaftarService
{
    public function __construct(
        private PantauanStokService $pantauan,
        private RiwayatStokService $riwayat,
    ) {
    }

    /** Kolom file penjualan harian; sama untuk Unduh Excel dan templat Impor Excel cabang. */
    private const KOLOM_PENJUALAN = ['Tanggal' => BukuExcel::TANGGAL, 'Kategori Menu' => null, 'Menu' => null, 'Porsi' => BukuExcel::BULAT];

    /**
     * Templat Impor Excel penjualan cabang: format sama dengan Unduh Excel penjualan, berisi semua menu aktif
     * dengan tanggal hari ini dan kolom Porsi kosong.
     *
     * @param  Collection<int, Menu>  $menu  menu aktif terurut
     * @param  array<int, int>  $porsi  [menu_id => porsi] untuk mengubah catatan yang sudah ada
     */
    public function templatPenjualan(Cabang $lokasi, Collection $menu, ?Carbon $tanggal = null, array $porsi = []): Spreadsheet
    {
        $judul = 'Penjualan Harian '.$lokasi->nama;
        $tanggal = ($tanggal ?? HariOperasional::tanggal())->copy()->startOfDay();
        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Penjualan', $judul, 'Isi kolom Porsi menu yang terjual (kosong = tidak terjual), lalu unggah lewat Impor Excel. Satu file untuk satu tanggal.',
            self::KOLOM_PENJUALAN,
            $menu->map(fn (Menu $m) => [$tanggal->copy(), $m->kategoriMenu->nama, $m->nama, $porsi[$m->id] ?? null]),
            teksKosong: 'Belum ada menu aktif.');

        return $buku;
    }

    /**
     * Stok satu lokasi (tombol Unduh Excel): angka kolom Stok bisa diubah lalu diunggah balik lewat Perbarui Lewat Excel;
     * lembar tersembunyi Data Sistem menyimpan stok saat diunduh.
     *
     * $contoh = templat (tombol Unduh Templat di popup Perbarui Lewat Excel): format sama, tetapi hanya beberapa bahan per kategori dengan angka Stok
     * contoh, supaya terlihat cara isinya. Angka contoh itu data biasa, jadi templat yang langsung diunggah pun bisa disimpan.
     */
    public function stok(Cabang $lokasi, bool $contoh = false): Spreadsheet
    {
        $daftar = PantauanStokService::kelompokkanPerKategori($this->pantauan->daftar($lokasi))
            ->flatMap(fn (array $k) => $k['baris']->sortBy(fn (array $r) => $r['bahan']->nama)
                ->when($contoh, fn ($baris) => $baris->filter(fn (array $r) => $r['bahan']->aktif)->take(ContohTemplat::PER_KATEGORI)))
            ->values();

        $kolom = ['Kategori' => null, 'Bahan' => null, 'Satuan' => null, 'Stok' => BukuExcel::JUMLAH, 'Menunggu' => BukuExcel::JUMLAH,
            'Minimum' => BukuExcel::JUMLAH, 'Pakai per Hari' => BukuExcel::JUMLAH, 'Cukup (Hari)' => BukuExcel::JUMLAH, 'Status' => null, 'Keterangan' => null];

        $buku = BukuExcel::baru('Stok '.$lokasi->nama);
        BukuExcel::lembar($buku, 'Stok', 'Stok '.$lokasi->nama,
            $contoh
                ? 'Isi angka di kolom Stok dalam satuan beli, tambah baris bahan lain bila perlu, lalu unggah lewat Perbarui Lewat Excel.'
                : 'Stok saat diunduh, dalam satuan beli. Untuk memperbarui: ubah angka di kolom Stok (Keterangan boleh diisi), lalu unggah lewat Perbarui Lewat Excel.',
            $kolom,
            $daftar->map(function (array $r, int $i) use ($contoh) {
                $b = $r['bahan'];

                return [
                    $b->kategori->nama, $b->nama, $b->satuanBeli->nama,
                    $contoh ? ContohTemplat::angka($i) : $b->keSatuanBeli($r['saldo']),
                    $r['menunggu'] != 0 ? $b->keSatuanBeli($r['menunggu']) : null,
                    $r['minimum'] > 0 ? $b->keSatuanBeli($r['minimum']) : null,
                    $r['pakai_per_hari'] > 0 ? $b->keSatuanBeli($r['pakai_per_hari']) : null,
                    $r['cukup_hari'],
                    $contoh ? null : PantauanStokService::teks($r['status']),
                    null,
                ];
            }),
            teksKosong: 'Belum ada bahan. Isi Bahan Baku dulu, lalu unduh lagi.'
        );

        ImporPenyesuaianStokService::tulisStokUnduh($buku, $lokasi, $daftar->map(fn (array $r) => [$r['bahan']->nama, $r['bahan']->keSatuanBeli($r['saldo'])]));

        return $buku;
    }

    /**
     * Riwayat masuk & keluar per transaksi, atau pemakaian harian, dengan saringan yang sama seperti halaman.
     *
     * @param  array{arah: string, jenis: string, kategori: ?int, cari: string}  $filter
     */
    public function riwayatStok(Cabang $lokasi, Periode $periode, array $filter, bool $harian, User $pengguna): Spreadsheet
    {
        $judul = ($harian ? 'Pemakaian harian ' : 'Riwayat stok ').$lokasi->nama;
        $info = 'Periode: '.$periode->label().$this->teksSaringan($filter);
        $buku = BukuExcel::baru($judul);

        if ($harian) {
            $baris = $this->riwayat->harian($lokasi, $periode, $filter)
                ->flatMap(fn (array $h) => $h['baris']->map(fn (array $r) => [
                    $h['tanggal']->copy(), $r['bahan']->nama, $r['bahan']->satuanBeli->nama,
                    $r['bahan']->keSatuanBeli($r['pakai']), $r['bahan']->keSatuanBeli($r['rusak']),
                ]));

            BukuExcel::lembar($buku, 'Pemakaian Harian', $judul, $info,
                ['Tanggal' => BukuExcel::TANGGAL, 'Bahan' => null, 'Satuan' => null, 'Dipakai' => BukuExcel::JUMLAH, 'Rusak' => BukuExcel::JUMLAH],
                $baris, ['Dipakai', 'Rusak']);

            return $buku;
        }

        $mutasi = $this->riwayat->semuaTransaksi($lokasi, $periode, $filter);
        $dokumen = TautanDokumen::untuk($pengguna, $mutasi);

        BukuExcel::lembar($buku, 'Masuk & Keluar', $judul, $info,
            ['Waktu' => BukuExcel::WAKTU, 'Bahan' => null, 'Jenis' => null, 'Dokumen' => null, 'Satuan' => null,
                'Masuk' => BukuExcel::JUMLAH, 'Keluar' => BukuExcel::JUMLAH, 'Oleh' => null],
            $mutasi->map(function (StokMutasi $m) use ($dokumen) {
                $b = $m->bahanBaku;
                $jumlah = $b->keSatuanBeli((float) $m->jumlah);

                return [
                    $m->dibuat_pada->copy(), $b->nama, $m->labelJenis(), $dokumen[$m->id]['nomor'] ?? null, $b->satuanBeli->nama,
                    $jumlah > 0 ? $jumlah : null, $jumlah < 0 ? -$jumlah : null, $m->pembuat?->nama ?? 'Sistem',
                ];
            }));

        return $buku;
    }

    /**
     * Stok per bahan pada satu tanggal (awal, masuk, keluar, akhir) dengan saringan yang sama seperti halaman.
     *
     * @param  array{arah: string, jenis: string, kategori: ?int, cari: string}  $filter
     */
    public function stokPerHari(Cabang $lokasi, Carbon $tanggal, array $filter, bool $semua): Spreadsheet
    {
        $judul = 'Stok per Hari '.$lokasi->nama;
        $buku = BukuExcel::baru($judul);

        BukuExcel::lembar($buku, 'Stok per Hari', $judul, 'Tanggal: '.$tanggal->translatedFormat('l, j M Y').$this->teksSaringan($filter),
            ['Kategori' => null, 'Bahan' => null, 'Satuan' => null, 'Stok Awal' => BukuExcel::JUMLAH, 'Masuk' => BukuExcel::JUMLAH,
                'Keluar' => BukuExcel::JUMLAH, 'Stok Akhir' => BukuExcel::JUMLAH, 'Rincian' => null],
            $this->riwayat->perHari($lokasi, $tanggal, $filter, $semua)->map(function (array $r) {
                $b = $r['bahan'];

                return [
                    $b->kategori->nama, $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli($r['awal']),
                    $r['masuk'] > 0 ? $b->keSatuanBeli($r['masuk']) : null,
                    $r['keluar'] > 0 ? $b->keSatuanBeli($r['keluar']) : null,
                    $b->keSatuanBeli($r['akhir']),
                    collect($r['rincian'])->map(fn (float $j, string $label) => $label.' '.($j > 0 ? '+' : '').Angka::tampil($b->keSatuanBeli($j)))->implode('; ') ?: null,
                ];
            }),
            teksKosong: 'Tidak ada perubahan stok pada tanggal ini.'
        );

        return $buku;
    }

    /**
     * Hasil satu stock opname, urut kategori lalu bahan. Stok sistem dan selisih hanya bila $lengkap (Admin Utama, atau
     * opname yang sudah disetujui), sama seperti PDF-nya. Kolom Bahan dan Hitung Fisik bisa dibaca lagi oleh Impor Opname.
     */
    public function hasilOpname(Opname $opname, bool $lengkap): Spreadsheet
    {
        $opname->loadMissing(['cabang:id,nama', 'petugas:id,nama']);
        $detail = $opname->detail()
            ->with(['bahanBaku:id,nama,kategori_id,satuan_beli_id,isi_per_satuan_beli', 'bahanBaku.satuanBeli:id,nama', 'bahanBaku.kategori:id,nama,urutan'])
            ->get()
            ->sortBy(fn (OpnameDetail $d) => [$d->bahanBaku->kategori->urutan, $d->bahanBaku->kategori->nama, $d->bahanBaku->nama])
            ->values();

        $angka = fn (OpnameDetail $d, $qty) => $qty === null ? null : $d->bahanBaku->keSatuanBeli((float) $qty);
        $kolom = ['Kategori' => null, 'Bahan' => null, 'Satuan' => null]
            + ($lengkap ? ['Stok Sistem' => BukuExcel::JUMLAH] : [])
            + ['Hitung Fisik' => BukuExcel::JUMLAH]
            + ($lengkap ? ['Selisih' => BukuExcel::JUMLAH] : [])
            + ['Catatan' => null];

        $judul = 'Hasil Opname '.$opname->nomor;
        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Hasil Opname', $judul,
            $opname->cabang->nama.' · '.$opname->tanggal->translatedFormat('j F Y').' · petugas '.($opname->petugas?->nama ?? '-')
                .' · '.(Opname::TAMPILAN_STATUS[$opname->status][0] ?? $opname->status),
            $kolom,
            $detail->map(fn (OpnameDetail $d) => [
                $d->bahanBaku->kategori->nama,
                $d->bahanBaku->nama,
                $d->bahanBaku->satuanBeli->nama,
                ...($lengkap ? [$angka($d, $d->qty_sistem)] : []),
                $angka($d, $d->qty_fisik),
                ...($lengkap ? [$d->qty_fisik === null ? null : $angka($d, (float) $d->qty_fisik - (float) $d->qty_sistem)] : []),
                $d->catatan,
            ]),
            [], 'Belum ada bahan di opname ini.');

        return $buku;
    }

    public function barangRusak(Cabang $lokasi, Periode $periode): Spreadsheet
    {
        $judul = 'Barang Rusak '.$lokasi->nama;
        $detail = BarangKeluarDetail::query()
            ->whereHas('barangKeluar', fn (Builder $q) => $q->diLokasi($lokasi->id)->rusak()
                ->whereDate('tanggal', '>=', $periode->dariTanggal())->whereDate('tanggal', '<=', $periode->sampaiTanggal()))
            ->with(['barangKeluar.pencatat:id,nama', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (BarangKeluarDetail $d) => [$d->barangKeluar->tanggal->toDateString(), $d->barangKeluar->nomor, $d->id])
            ->values();

        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Barang Rusak', $judul, 'Periode: '.$periode->label(),
            ['Tanggal' => BukuExcel::TANGGAL, 'Nomor' => null, 'Bahan' => null, 'Satuan' => null, 'Jumlah' => BukuExcel::JUMLAH,
                'Alasan' => null, 'Status' => null, 'Dicatat oleh' => null],
            $detail->map(fn (BarangKeluarDetail $d) => [
                $d->barangKeluar->tanggal->copy(), $d->barangKeluar->nomor, $d->bahanBaku->nama, $d->bahanBaku->satuanBeli->nama,
                $d->bahanBaku->keSatuanBeli((float) $d->qty), $d->penjelasan,
                $d->barangKeluar->label_status, $d->barangKeluar->pencatat?->nama,
            ]));

        return $buku;
    }

    /** Kiriman keluar dari lokasi (Gudang Pusat) atau masuk ke lokasi (cabang). */
    public function kiriman(Cabang $lokasi, Periode $periode, bool $masuk): Spreadsheet
    {
        $judul = ($masuk ? 'Kiriman ke ' : 'Kiriman dari ').$lokasi->nama;
        $detail = KirimanDetail::query()
            ->whereHas('kiriman', fn (Builder $q) => ($masuk ? $q->ke($lokasi->id) : $q->dari($lokasi->id))
                ->where('status', '!=', Kiriman::STATUS_DISIAPKAN)
                ->whereBetween('dikirim_pada', [$periode->dari, $periode->sampai]))
            ->with(['kiriman.keCabang:id,nama', 'kiriman.dariCabang:id,nama', 'kiriman.permintaan:id,nomor', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (KirimanDetail $d) => [$d->kiriman->dikirim_pada?->timestamp, $d->kiriman->nomor, $d->id])
            ->values();

        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Kiriman', $judul, 'Periode: '.$periode->label(),
            ['Dikirim' => BukuExcel::WAKTU, 'Nomor' => null, $masuk ? 'Dari' : 'Tujuan' => null, 'Permintaan' => null, 'Bahan' => null,
                'Satuan' => null, 'Dikirim (Jumlah)' => BukuExcel::JUMLAH, 'Diterima' => BukuExcel::JUMLAH, 'Selisih' => BukuExcel::JUMLAH,
                'Kondisi' => null, 'Catatan Penerima' => null, 'Status' => null],
            $detail->map(function (KirimanDetail $d) use ($masuk) {
                $k = $d->kiriman;
                $b = $d->bahanBaku;
                $diterima = $d->sudahDichecklist();

                return [
                    $k->dikirim_pada?->copy(), $k->nomor, $masuk ? $k->dariCabang?->nama : $k->keCabang?->nama, $k->permintaan?->nomor,
                    $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli((float) $d->qty_kirim),
                    $diterima ? $b->keSatuanBeli((float) $d->qty_terima) : null,
                    $diterima ? $b->keSatuanBeli((float) $d->qty_terima - (float) $d->qty_kirim) : null,
                    $d->kondisi ? KirimanDetail::LABEL_KONDISI[$d->kondisi] : null, $d->catatan_penerima, $k->tampilanStatus()[0],
                ];
            }));

        return $buku;
    }

    public function permintaan(Cabang $lokasi, Periode $periode): Spreadsheet
    {
        $judul = 'Permintaan '.$lokasi->nama;
        $detail = PermintaanDetail::query()
            ->whereHas('permintaan', fn (Builder $q) => $q->diLokasi($lokasi->id)
                ->whereBetween('diajukan_pada', [$periode->dari, $periode->sampai]))
            ->with(['permintaan:id,nomor,diajukan_pada', 'bahanBaku.satuanBeli:id,nama'])
            ->get()
            ->sortBy(fn (PermintaanDetail $d) => [$d->permintaan->diajukan_pada->timestamp, $d->permintaan->nomor, $d->id])
            ->values();

        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Permintaan', $judul, 'Periode: '.$periode->label(),
            ['Diajukan' => BukuExcel::WAKTU, 'Nomor' => null, 'Bahan' => null, 'Satuan' => null,
                'Diminta' => BukuExcel::JUMLAH, 'Disetujui' => BukuExcel::JUMLAH, 'Dikirim' => BukuExcel::JUMLAH, 'Status' => null, 'Alasan' => null],
            $detail->map(function (PermintaanDetail $d) {
                $b = $d->bahanBaku;

                return [
                    $d->permintaan->diajukan_pada->copy(), $d->permintaan->nomor,
                    $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli((float) $d->qty_diminta),
                    $d->qty_disetujui !== null ? $b->keSatuanBeli((float) $d->qty_disetujui) : null,
                    $d->qty_dikirim !== null ? $b->keSatuanBeli((float) $d->qty_dikirim) : null,
                    $d->tampilanStatus()[0], $d->alasan,
                ];
            }));

        return $buku;
    }

    /** Satu permintaan, satu baris per bahan. Kolom Bahan dan Diminta bisa dibaca lagi oleh Impor Permintaan. */
    public function satuPermintaan(Permintaan $permintaan): Spreadsheet
    {
        $permintaan->loadMissing(['cabang:id,nama', 'pengaju:id,nama', 'detail.bahanBaku.satuanBeli:id,nama']);
        $judul = 'Permintaan '.$permintaan->nomor;

        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Permintaan', $judul,
            $permintaan->cabang->nama.' · diajukan '.$permintaan->diajukan_pada->translatedFormat('j F Y H.i')
                .($permintaan->pengaju ? ' oleh '.$permintaan->pengaju->nama : ''),
            ['Bahan' => null, 'Satuan' => null, 'Diminta' => BukuExcel::JUMLAH, 'Disetujui' => BukuExcel::JUMLAH,
                'Dikirim' => BukuExcel::JUMLAH, 'Status' => null, 'Alasan' => null],
            $permintaan->detail->sortBy('id')->values()->map(function (PermintaanDetail $d) {
                $b = $d->bahanBaku;

                return [
                    $b->nama, $b->satuanBeli->nama, $b->keSatuanBeli((float) $d->qty_diminta),
                    $d->qty_disetujui !== null ? $b->keSatuanBeli((float) $d->qty_disetujui) : null,
                    $d->qty_dikirim !== null ? $b->keSatuanBeli((float) $d->qty_dikirim) : null,
                    $d->tampilanStatus()[0], $d->alasan,
                ];
            }),
            [], 'Tidak ada bahan di permintaan ini.');

        return $buku;
    }

    public function penjualan(Cabang $lokasi, Periode $periode): Spreadsheet
    {
        $judul = 'Penjualan Harian '.$lokasi->nama;
        $detail = PenjualanDetail::query()
            ->whereHas('penjualanHarian', fn (Builder $q) => $q->diLokasi($lokasi->id)
                ->whereDate('tanggal', '>=', $periode->dariTanggal())->whereDate('tanggal', '<=', $periode->sampaiTanggal()))
            ->with(['penjualanHarian:id,tanggal', 'menu:id,nama,kategori_menu_id', 'menu.kategoriMenu:id,nama,urutan'])
            ->get()
            ->sortBy(fn (PenjualanDetail $d) => [$d->penjualanHarian->tanggal->toDateString(), $d->menu->kategoriMenu->urutan, $d->menu->nama])
            ->values();

        $buku = BukuExcel::baru($judul);
        BukuExcel::lembar($buku, 'Penjualan', $judul, 'Periode: '.$periode->label(), self::KOLOM_PENJUALAN,
            $detail->map(fn (PenjualanDetail $d) => [
                $d->penjualanHarian->tanggal->copy(), $d->menu->kategoriMenu->nama, $d->menu->nama, (int) $d->porsi,
            ]),
            ['Porsi']);

        return $buku;
    }

    private function teksSaringan(array $filter): string
    {
        $bagian = array_filter([
            $filter['arah'] !== '' ? RiwayatStokService::LABEL_ARAH[$filter['arah']] : null,
            $filter['jenis'] !== '' ? StokMutasi::LABEL_JENIS[$filter['jenis']] : null,
            $filter['kategori'] ? Kategori::query()->whereKey($filter['kategori'])->value('nama') : null,
            $filter['cari'] !== '' ? '"'.$filter['cari'].'"' : null,
        ]);

        return $bagian === [] ? '' : ' · '.implode(', ', $bagian);
    }
}
