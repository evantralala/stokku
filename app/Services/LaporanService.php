<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Opname;
use App\Models\StokMutasi;
use App\Support\Periode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data laporan Admin Utama. Semua jumlah dalam satuan dasar; tampilan mengubahnya ke satuan beli.
 * Pergerakan stok dihitung dari mutasi yang sah menurut waktu pencatatannya (dibuat_pada).
 * Dipakai halaman laporan dan ekspor Excel.
 */
class LaporanService
{
    /** Kolom rekap stok: jenis mutasi => judul kolom, urut sesuai alur barang. */
    public const KOLOM_REKAP = [
        StokMutasi::JENIS_STOK_AWAL      => 'Stok Awal',
        StokMutasi::JENIS_MASUK_SUPPLIER => 'Barang Masuk',
        StokMutasi::JENIS_TAMBAH_LANGSUNG => 'Tambah Langsung',
        StokMutasi::JENIS_KIRIM_MASUK    => 'Diterima',
        StokMutasi::JENIS_KIRIM_KELUAR   => 'Dikirim',
        StokMutasi::JENIS_PAKAI_DAPUR    => 'Pemakaian',
        StokMutasi::JENIS_RUSAK          => 'Rusak',
        StokMutasi::JENIS_KOREKSI_OPNAME => 'Koreksi Opname',
        StokMutasi::JENIS_PENYESUAIAN    => 'Penyesuaian',
    ];

    /** Kolom rekap yang hanya tampil bila periode itu ada pergerakannya. */
    public const KOLOM_REKAP_BILA_ADA = [StokMutasi::JENIS_STOK_AWAL, StokMutasi::JENIS_TAMBAH_LANGSUNG, StokMutasi::JENIS_PENYESUAIAN];

    /**
     * Kolom rekap untuk ditampilkan: KOLOM_REKAP tanpa kolom KOLOM_REKAP_BILA_ADA yang semuanya nol.
     *
     * @param  Collection<int, array{jenis: array<string, float>}>  $rekap
     * @return array<string, string>
     */
    public static function kolomRekap(Collection $rekap): array
    {
        return array_filter(
            self::KOLOM_REKAP,
            fn (string $jenis) => ! in_array($jenis, self::KOLOM_REKAP_BILA_ADA, true)
                || $rekap->contains(fn (array $r) => ($r['jenis'][$jenis] ?? 0.0) != 0.0),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Rekap stok satu lokasi: awal, pergerakan per jenis, dan akhir periode.
     *
     * @return Collection<int, array{bahan: BahanBaku, awal: float, jenis: array<string, float>, akhir: float}>
     */
    public function rekapStok(Cabang $lokasi, Periode $periode, ?int $kategoriId = null): Collection
    {
        $awal = $this->mutasiSah($lokasi->id)
            ->where('dibuat_pada', '<', $periode->dari)
            ->groupBy('bahan_baku_id')
            ->selectRaw('bahan_baku_id, sum(jumlah) as total')
            ->pluck('total', 'bahan_baku_id');

        $gerak = $this->mutasiSah($lokasi->id)
            ->whereBetween('dibuat_pada', [$periode->dari, $periode->sampai])
            ->groupBy('bahan_baku_id', 'jenis')
            ->selectRaw('bahan_baku_id, jenis, sum(jumlah) as total')
            ->get()
            ->groupBy('bahan_baku_id');

        $bahanIds = $awal->filter(fn ($t) => (float) $t != 0.0)->keys()->merge($gerak->keys())->unique();

        return BahanBaku::query()
            ->whereKey($bahanIds->all())
            ->when($kategoriId, fn ($q, int $id) => $q->where('kategori_id', $id))
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama', 'satuanDasar:id,nama'])
            ->get()
            ->sortBy(fn (BahanBaku $b) => [$b->kategori->urutan, $b->kategori->nama, $b->nama])
            ->values()
            ->map(function (BahanBaku $b) use ($awal, $gerak) {
                $jenis = collect(self::KOLOM_REKAP)->map(fn () => 0.0)->all();

                foreach ($gerak[$b->id] ?? [] as $g) {
                    $jenis[$g->jenis] = round((float) $g->total, 3);
                }

                $mulai = round((float) ($awal[$b->id] ?? 0), 3);

                return [
                    'bahan' => $b,
                    'awal'  => $mulai,
                    'jenis' => $jenis,
                    'akhir' => round($mulai + array_sum($jenis), 3),
                ];
            });
    }

    /**
     * Porsi terjual per menu.
     *
     * @return Collection<int, object{nama: string, kategori: string, porsi: int}>
     */
    public function penjualanMenu(?Cabang $lokasi, Periode $periode): Collection
    {
        return DB::table('penjualan_detail')
            ->join('penjualan_harian', 'penjualan_harian.id', '=', 'penjualan_detail.penjualan_harian_id')
            ->join('menu', 'menu.id', '=', 'penjualan_detail.menu_id')
            ->join('kategori_menu', 'kategori_menu.id', '=', 'menu.kategori_menu_id')
            ->whereNull('penjualan_detail.deleted_at')
            ->whereNull('penjualan_harian.deleted_at')
            ->when($lokasi, fn ($q) => $q->where('penjualan_harian.cabang_id', $lokasi->id))
            ->whereDate('penjualan_harian.tanggal', '>=', $periode->dariTanggal())
            ->whereDate('penjualan_harian.tanggal', '<=', $periode->sampaiTanggal())
            ->groupBy('menu.id', 'menu.nama', 'kategori_menu.nama', 'kategori_menu.urutan')
            ->orderBy('kategori_menu.urutan')
            ->orderByDesc(DB::raw('sum(penjualan_detail.porsi)'))
            ->selectRaw('menu.nama as nama, kategori_menu.nama as kategori, sum(penjualan_detail.porsi) as porsi')
            ->get()
            ->map(fn ($r) => (object) [
                'nama'     => $r->nama,
                'kategori' => $r->kategori,
                'porsi'    => (int) $r->porsi,
            ]);
    }

    /**
     * Ke mana bahan pergi di dapur cabang: pemakaian menurut resep, rusak, dan selisih yang ditemukan opname atau penyesuaian stok.
     * "Hilang" = koreksi opname atau penyesuaian negatif (fisik lebih sedikit dari catatan), dibandingkan dengan pemakaian.
     *
     * @return Collection<int, array{bahan: BahanBaku, pemakaian: float, rusak: float, hilang: float, lebih: float, persen_hilang: ?float}>
     */
    public function pemakaianBahan(?Cabang $lokasi, Periode $periode, ?int $kategoriId = null): Collection
    {
        $lokasiIds = $lokasi ? [$lokasi->id] : Cabang::query()->cabang()->pluck('id')->all();

        $data = StokMutasi::query()
            ->sah()
            ->whereIn('cabang_id', $lokasiIds)
            ->whereIn('jenis', [StokMutasi::JENIS_PAKAI_DAPUR, StokMutasi::JENIS_RUSAK, StokMutasi::JENIS_KOREKSI_OPNAME, StokMutasi::JENIS_PENYESUAIAN])
            ->whereBetween('dibuat_pada', [$periode->dari, $periode->sampai])
            ->groupBy('bahan_baku_id')
            ->selectRaw('bahan_baku_id,
                sum(case when jenis = ? then jumlah else 0 end) as pakai,
                sum(case when jenis = ? then jumlah else 0 end) as rusak,
                sum(case when jenis in (?, ?) and jumlah < 0 then jumlah else 0 end) as hilang,
                sum(case when jenis in (?, ?) and jumlah > 0 then jumlah else 0 end) as lebih', [
                StokMutasi::JENIS_PAKAI_DAPUR, StokMutasi::JENIS_RUSAK,
                StokMutasi::JENIS_KOREKSI_OPNAME, StokMutasi::JENIS_PENYESUAIAN,
                StokMutasi::JENIS_KOREKSI_OPNAME, StokMutasi::JENIS_PENYESUAIAN,
            ])
            ->get()
            ->keyBy('bahan_baku_id');

        return BahanBaku::query()
            ->whereKey($data->keys()->all())
            ->when($kategoriId, fn ($q, int $id) => $q->where('kategori_id', $id))
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama', 'satuanDasar:id,nama'])
            ->orderBy('nama')
            ->get()
            ->map(function (BahanBaku $b) use ($data) {
                $r = $data[$b->id];
                $pakai = round(-(float) $r->pakai, 3);
                $hilang = round(-(float) $r->hilang, 3);

                return [
                    'bahan'         => $b,
                    'pemakaian'     => $pakai,
                    'rusak'         => round(-(float) $r->rusak, 3),
                    'hilang'        => $hilang,
                    'lebih'         => round((float) $r->lebih, 3),
                    'persen_hilang' => $pakai > 0 ? round($hilang / $pakai * 100, 1) : null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => [$a['bahan']->kategori->urutan, $a['bahan']->kategori->nama] <=> [$b['bahan']->kategori->urutan, $b['bahan']->kategori->nama],
                fn ($a, $b) => ($b['persen_hilang'] ?? -1) <=> ($a['persen_hilang'] ?? -1),
            ])
            ->values();
    }

    /**
     * Ringkasan penjualan per kategori menu (gaya Majoo): jumlah menu terjual, porsi, persen dari total, dan menu terlaris.
     *
     * @param  Collection<int, object>  $menu  hasil penjualanMenu()
     * @return Collection<int, array{kategori: string, menu: int, porsi: int, persen: float, terlaris: ?object}>
     */
    public static function penjualanPerKategori(Collection $menu): Collection
    {
        $total = max(1, $menu->sum('porsi'));

        return $menu->groupBy('kategori')
            ->map(fn (Collection $daftar, string $kategori) => [
                'kategori' => $kategori,
                'menu'     => $daftar->where('porsi', '>', 0)->count(),
                'porsi'    => (int) $daftar->sum('porsi'),
                'persen'   => round($daftar->sum('porsi') / $total * 100, 1),
                'terlaris' => $daftar->sortByDesc('porsi')->first(),
            ])
            ->sortByDesc('porsi')
            ->values();
    }

    /**
     * Ringkasan bahan dapur per kategori bahan: berapa bahan terpakai, rusak, dan hilang saat opname.
     * Jumlah antarbahan tidak dijumlahkan karena satuannya berbeda.
     *
     * @param  Collection<int, array>  $bahan  hasil pemakaianBahan()
     * @return Collection<int, array{kategori: string, dipakai: int, rusak: int, hilang: int, lewat_toleransi: int}>
     */
    public static function pemakaianPerKategori(Collection $bahan): Collection
    {
        return $bahan->groupBy(fn (array $r) => $r['bahan']->kategori->nama)
            ->map(fn (Collection $daftar, string $kategori) => [
                'kategori'        => $kategori,
                'dipakai'         => $daftar->where('pemakaian', '>', 0)->count(),
                'rusak'           => $daftar->where('rusak', '>', 0)->count(),
                'hilang'          => $daftar->where('hilang', '>', 0)->count(),
                'lewat_toleransi' => $daftar->filter(fn (array $r) => ($r['persen_hilang'] ?? 0) > $r['bahan']->toleransi_persen)->count(),
            ])
            ->values();
    }

    /**
     * Barang kiriman yang jumlah terimanya berbeda dari jumlah kirim.
     *
     * @return Collection<int, object>
     */
    public function selisihKiriman(?Cabang $lokasi, Periode $periode): Collection
    {
        return DB::table('kiriman_detail')
            ->join('kiriman', 'kiriman.id', '=', 'kiriman_detail.kiriman_id')
            ->join('cabang', 'cabang.id', '=', 'kiriman.ke_cabang_id')
            ->join('bahan_baku', 'bahan_baku.id', '=', 'kiriman_detail.bahan_baku_id')
            ->join('satuan', 'satuan.id', '=', 'bahan_baku.satuan_beli_id')
            ->whereNull('kiriman_detail.deleted_at')
            ->whereNotNull('kiriman_detail.dichecklist_pada')
            ->whereColumn('kiriman_detail.qty_terima', '!=', 'kiriman_detail.qty_kirim')
            ->when($lokasi, fn ($q) => $q->where('kiriman.ke_cabang_id', $lokasi->id))
            ->whereBetween('kiriman.sampai_pada', [$periode->dari, $periode->sampai])
            ->orderBy('kiriman.sampai_pada')
            ->get([
                'kiriman.sampai_pada as waktu', 'kiriman.nomor', 'cabang.nama as lokasi', 'bahan_baku.nama as bahan',
                'bahan_baku.isi_per_satuan_beli as isi', 'satuan.nama as satuan', 'bahan_baku.toleransi_persen as toleransi',
                'kiriman_detail.qty_kirim as kirim', 'kiriman_detail.qty_terima as terima', 'kiriman_detail.kondisi',
                'kiriman_detail.catatan_penerima as catatan',
            ]);
    }

    /**
     * Bahan opname yang disetujui dengan hitung fisik berbeda dari stok sistem.
     *
     * @return Collection<int, object>
     */
    public function selisihOpname(?Cabang $lokasi, Periode $periode): Collection
    {
        return DB::table('opname_detail')
            ->join('opname', 'opname.id', '=', 'opname_detail.opname_id')
            ->join('cabang', 'cabang.id', '=', 'opname.cabang_id')
            ->join('bahan_baku', 'bahan_baku.id', '=', 'opname_detail.bahan_baku_id')
            ->join('satuan', 'satuan.id', '=', 'bahan_baku.satuan_beli_id')
            ->whereNull('opname_detail.deleted_at')
            ->where('opname.status', Opname::STATUS_DISETUJUI)
            ->whereNotNull('opname_detail.qty_fisik')
            ->whereColumn('opname_detail.qty_fisik', '!=', 'opname_detail.qty_sistem')
            ->when($lokasi, fn ($q) => $q->where('opname.cabang_id', $lokasi->id))
            ->whereDate('opname.tanggal', '>=', $periode->dariTanggal())
            ->whereDate('opname.tanggal', '<=', $periode->sampaiTanggal())
            ->orderBy('opname.tanggal')
            ->get([
                'opname.tanggal as waktu', 'opname.nomor', 'cabang.nama as lokasi', 'bahan_baku.nama as bahan',
                'bahan_baku.isi_per_satuan_beli as isi', 'satuan.nama as satuan', 'bahan_baku.toleransi_persen as toleransi',
                'opname_detail.qty_sistem as sistem', 'opname_detail.qty_fisik as fisik', 'opname_detail.catatan',
            ]);
    }

    /**
     * Barang rusak/terbuang yang disetujui: ringkasan per bahan.
     *
     * @return array{per_bahan: Collection}
     */
    public function barangRusak(?Cabang $lokasi, Periode $periode): array
    {
        $perBahan = DB::table('barang_keluar_detail')
            ->join('barang_keluar', 'barang_keluar.id', '=', 'barang_keluar_detail.barang_keluar_id')
            ->whereNull('barang_keluar_detail.deleted_at')
            ->where('barang_keluar.jenis', BarangKeluar::JENIS_RUSAK)
            ->where('barang_keluar.status', BarangKeluar::STATUS_DISETUJUI)
            ->when($lokasi, fn ($q) => $q->where('barang_keluar.cabang_id', $lokasi->id))
            ->whereDate('barang_keluar.tanggal', '>=', $periode->dariTanggal())
            ->whereDate('barang_keluar.tanggal', '<=', $periode->sampaiTanggal())
            ->join('bahan_baku', 'bahan_baku.id', '=', 'barang_keluar_detail.bahan_baku_id')
            ->join('satuan', 'satuan.id', '=', 'bahan_baku.satuan_beli_id')
            ->groupBy('bahan_baku.id', 'bahan_baku.nama', 'bahan_baku.isi_per_satuan_beli', 'satuan.nama')
            ->selectRaw('bahan_baku.nama as bahan, bahan_baku.isi_per_satuan_beli as isi, satuan.nama as satuan,
                sum(barang_keluar_detail.qty) as qty, count(*) as kali')
            ->orderByDesc('kali')
            ->orderBy('bahan_baku.nama')
            ->get();

        return ['per_bahan' => $perBahan];
    }

    private function mutasiSah(int $lokasiId)
    {
        return StokMutasi::query()->sah()->where('cabang_id', $lokasiId);
    }
}
