<?php

namespace App\Services;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\StokMutasi;
use App\Support\Periode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat stok satu lokasi: stok per hari (awal, masuk, keluar, akhir satu tanggal),
 * barang masuk & keluar per transaksi (mutasi sah menurut waktu dicatat),
 * dan pemakaian harian (pemakaian dapur dan barang rusak menurut tanggal dokumennya).
 */
class RiwayatStokService
{
    public const ARAH_MASUK = 'masuk';
    public const ARAH_KELUAR = 'keluar';

    public const LABEL_ARAH = [
        self::ARAH_MASUK  => 'Masuk',
        self::ARAH_KELUAR => 'Keluar',
    ];

    public const PER_HALAMAN = 50;

    /**
     * @param  array{arah: string, jenis: string, kategori: ?int, cari: string}  $filter
     * @return LengthAwarePaginator<StokMutasi>
     */
    public function transaksi(Cabang $lokasi, Periode $periode, array $filter): LengthAwarePaginator
    {
        return $this->queryTransaksi($lokasi, $periode, $filter)
            ->paginate(self::PER_HALAMAN)
            ->withQueryString();
    }

    /**
     * Semua transaksi periode itu tanpa halaman, untuk diunduh.
     *
     * @return Collection<int, StokMutasi>
     */
    public function semuaTransaksi(Cabang $lokasi, Periode $periode, array $filter): Collection
    {
        return $this->queryTransaksi($lokasi, $periode, $filter)->get();
    }

    private function queryTransaksi(Cabang $lokasi, Periode $periode, array $filter): Builder
    {
        return StokMutasi::query()
            ->where('cabang_id', $lokasi->id)
            ->sah()
            ->whereBetween('dibuat_pada', [$periode->dari, $periode->sampai])
            ->when($filter['arah'] === self::ARAH_MASUK, fn (Builder $q) => $q->where('jumlah', '>', 0))
            ->when($filter['arah'] === self::ARAH_KELUAR, fn (Builder $q) => $q->where('jumlah', '<', 0))
            ->when($filter['jenis'] !== '', fn (Builder $q) => $q->where('jenis', $filter['jenis']))
            ->when($filter['kategori'] || $filter['cari'] !== '', fn (Builder $q) => $q->whereIn('bahan_baku_id', $this->bahanSaring($filter)))
            ->with(['bahanBaku:id,nama,satuan_beli_id,isi_per_satuan_beli', 'bahanBaku.satuanBeli:id,nama', 'pembuat:id,nama'])
            ->orderByDesc('dibuat_pada')
            ->orderByDesc('id');
    }

    /**
     * Stok per bahan pada satu tanggal: stok awal (akhir hari sebelumnya), masuk, keluar, stok akhir,
     * dan rincian per jenis mutasi (bersih). Mutasi sah dihitung menurut waktu dicatat, sama seperti laporan stok.
     * $semua = false: hanya bahan yang berubah hari itu; true: ditambah bahan aktif dan bahan yang masih punya stok.
     *
     * @param  array{kategori: ?int, cari: string}  $filter
     * @return Collection<int, array{bahan: BahanBaku, awal: float, masuk: float, keluar: float, akhir: float, rincian: array<string, float>}>
     */
    public function perHari(Cabang $lokasi, Carbon $tanggal, array $filter, bool $semua): Collection
    {
        $mulai = $tanggal->copy()->startOfDay();
        $saring = $filter['kategori'] || $filter['cari'] !== '' ? $this->bahanSaring($filter) : null;
        $dasar = fn () => StokMutasi::query()
            ->where('cabang_id', $lokasi->id)
            ->sah()
            ->when($saring !== null, fn (Builder $q) => $q->whereIn('bahan_baku_id', $saring));

        $awal = $dasar()
            ->where('dibuat_pada', '<', $mulai)
            ->groupBy('bahan_baku_id')
            ->selectRaw('bahan_baku_id, SUM(jumlah) AS total')
            ->pluck('total', 'bahan_baku_id')
            ->map(fn ($t) => round((float) $t, 3));

        $gerak = $dasar()
            ->whereBetween('dibuat_pada', [$mulai, $tanggal->copy()->endOfDay()])
            ->groupBy('bahan_baku_id', 'jenis')
            ->selectRaw('bahan_baku_id, jenis, SUM(CASE WHEN jumlah > 0 THEN jumlah ELSE 0 END) AS masuk, SUM(CASE WHEN jumlah < 0 THEN jumlah ELSE 0 END) AS keluar')
            ->get()
            ->groupBy('bahan_baku_id');

        $bahanIds = $gerak->keys()
            ->when($semua, fn (Collection $c) => $c->merge($awal->filter(fn (float $t) => $t != 0.0)->keys()))
            ->unique();

        return BahanBaku::query()
            ->where(fn (Builder $q) => $q->whereKey($bahanIds->all())->when($semua, fn (Builder $q) => $q->orWhere('aktif', true)))
            ->when($saring !== null, fn (Builder $q) => $q->whereKey($saring))
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama'])
            ->get(['id', 'nama', 'kategori_id', 'satuan_beli_id', 'isi_per_satuan_beli'])
            ->sortBy(fn (BahanBaku $b) => [$b->kategori->urutan, $b->kategori->nama, $b->nama])
            ->values()
            ->map(function (BahanBaku $b) use ($awal, $gerak) {
                $baris = $gerak[$b->id] ?? collect();
                $masuk = round((float) $baris->sum('masuk'), 3);
                $keluar = round(-(float) $baris->sum('keluar'), 3);
                $mulai = $awal[$b->id] ?? 0.0;
                $rincian = [];

                foreach (StokMutasi::LABEL_JENIS as $jenis => $label) {
                    $r = $baris->firstWhere('jenis', $jenis);
                    $bersih = $r ? round((float) $r->masuk + (float) $r->keluar, 3) : 0.0;

                    if ($r && $bersih != 0.0) {
                        $rincian[$label] = $bersih;
                    }
                }

                return [
                    'bahan'   => $b,
                    'awal'    => $mulai,
                    'masuk'   => $masuk,
                    'keluar'  => $keluar,
                    'akhir'   => round($mulai + $masuk - $keluar, 3),
                    'rincian' => $rincian,
                ];
            });
    }

    /**
     * Pemakaian per tanggal, terbaru dulu.
     *
     * @param  array{kategori: ?int, cari: string}  $filter
     * @return Collection<string, array{tanggal: Carbon, baris: Collection<int, array{bahan: BahanBaku, pakai: float, rusak: float}>}>
     */
    public function harian(Cabang $lokasi, Periode $periode, array $filter): Collection
    {
        $data = DB::table('stok_mutasi')
            ->join('barang_keluar', 'barang_keluar.id', '=', 'stok_mutasi.dokumen_id')
            ->where('stok_mutasi.dokumen_tipe', BarangKeluar::DOKUMEN_TIPE)
            ->where('stok_mutasi.cabang_id', $lokasi->id)
            ->where('stok_mutasi.status', StokMutasi::STATUS_SAH)
            ->whereIn('stok_mutasi.jenis', [StokMutasi::JENIS_PAKAI_DAPUR, StokMutasi::JENIS_RUSAK])
            ->whereBetween('barang_keluar.tanggal', [$periode->dariTanggal(), $periode->sampaiTanggal()])
            ->when($filter['kategori'] || $filter['cari'] !== '', fn ($q) => $q->whereIn('stok_mutasi.bahan_baku_id', $this->bahanSaring($filter)))
            ->groupBy('barang_keluar.tanggal', 'stok_mutasi.bahan_baku_id', 'stok_mutasi.jenis')
            ->selectRaw('barang_keluar.tanggal AS tanggal, stok_mutasi.bahan_baku_id AS bahan_id, stok_mutasi.jenis AS jenis, SUM(stok_mutasi.jumlah) AS total')
            ->get();

        $bahan = BahanBaku::query()
            ->whereKey($data->pluck('bahan_id')->unique()->all())
            ->with('satuanBeli:id,nama')
            ->get(['id', 'nama', 'satuan_beli_id', 'isi_per_satuan_beli'])
            ->keyBy('id');

        return $data
            ->groupBy(fn ($r) => Carbon::parse($r->tanggal)->toDateString())
            ->sortKeysDesc()
            ->map(function (Collection $hari, string $tanggal) use ($bahan) {
                $baris = $hari->groupBy('bahan_id')
                    ->map(function (Collection $r, $bahanId) use ($bahan) {
                        $pakai = -(float) $r->where('jenis', StokMutasi::JENIS_PAKAI_DAPUR)->sum('total');
                        $rusak = -(float) $r->where('jenis', StokMutasi::JENIS_RUSAK)->sum('total');

                        return ['bahan' => $bahan[$bahanId], 'pakai' => round(max(0, $pakai), 3), 'rusak' => round(max(0, $rusak), 3)];
                    })
                    ->filter(fn (array $r) => $r['pakai'] > 0 || $r['rusak'] > 0)
                    ->sortBy(fn (array $r) => $r['bahan']->nama)
                    ->values();

                return ['tanggal' => Carbon::parse($tanggal), 'baris' => $baris];
            })
            ->filter(fn (array $h) => $h['baris']->isNotEmpty());
    }

    /** @return list<int> */
    private function bahanSaring(array $filter): array
    {
        return BahanBaku::query()
            ->when($filter['kategori'], fn (Builder $q, int $id) => $q->where('kategori_id', $id))
            ->when($filter['cari'] !== '', fn (Builder $q) => $q->where('nama', 'like', '%'.$filter['cari'].'%'))
            ->pluck('id')
            ->all();
    }
}
