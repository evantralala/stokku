<?php

namespace App\Services;

use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Kiriman;
use App\Models\Opname;
use App\Models\OpnameDetail;
use App\Models\PenjualanHarian;
use App\Models\StokMutasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Peringatan dini untuk Admin Utama: pola yang patut diperiksa sebelum menjadi kerugian.
 * Setiap aturan menghasilkan peringatan per lokasi; hasil diurutkan dari tingkat tertinggi.
 * Ambang di bawah adalah aturan analisis, bukan data master.
 */
class PeringatanDiniService
{
    public const TINGKAT_TINGGI = 'tinggi';
    public const TINGKAT_SEDANG = 'sedang';

    public const TAMPILAN_TINGKAT = [
        self::TINGKAT_TINGGI => ['Perlu Segera', 'b-lewat'],
        self::TINGKAT_SEDANG => ['Perhatikan', 'b-tunggu'],
    ];

    /** Warna strip baris peringatan per tingkat (kelas lampu). */
    public const LAMPU_TINGKAT = [
        self::TINGKAT_TINGGI => 'lampu-merah',
        self::TINGKAT_SEDANG => 'lampu-kuning',
    ];

    /** Rentang hari pengamatan untuk pola berulang. */
    public const HARI_PENGAMATAN = 30;

    /** Selisih kiriman sebanyak ini dalam rentang pengamatan dianggap tinggi. */
    private const SELISIH_KIRIMAN_TINGGI = 2;

    /** Laporan barang rusak sebanyak ini dianggap berulang (sedang), dan sebanyak TINGGI dianggap tinggi. */
    private const RUSAK_BERULANG = 3;
    private const RUSAK_TINGGI = 5;

    /** Bahan opname di luar toleransi sebanyak ini dianggap tinggi. */
    private const OPNAME_LUAR_TINGGI = 3;

    /** Pemakaian sehari dianggap melonjak bila lebih dari sekian kali rata-rata 7 hari sebelumnya. */
    private const LONJAKAN_KALI = 2;
    private const HARI_PEMBANDING = 7;
    private const MIN_HARI_DATA = 3;

    /** Stok dikunci opname lebih lama dari ini (jam) perlu diperhatikan. */
    private const OPNAME_TERKUNCI_JAM = 24;

    /** Nama bahan yang ditampilkan dalam satu peringatan. */
    private const MAKS_NAMA = 3;

    /**
     * @return Collection<int, array{tingkat: string, lokasi: string, lokasi_id: int, judul: string, keterangan: string, tautan: ?string}>
     */
    public function daftar(): Collection
    {
        $lokasi = Cabang::query()->aktif()->orderBy('nama')->get();
        $cabang = $lokasi->reject->isPusat();

        $peringatan = collect()
            ->concat($this->stokMinus($lokasi))
            ->concat($this->selisihKiriman($cabang))
            ->concat($this->rusakBerulang($lokasi))
            ->concat($this->opnameSelisihBesar($lokasi))
            ->concat($this->opnameTerkunciLama($lokasi))
            ->concat($this->pemakaianMelonjak($cabang))
            ->concat($this->penjualanBelumDicatat($cabang));

        $urutan = array_flip(array_keys(self::TAMPILAN_TINGKAT));

        return $peringatan->sortBy(fn (array $p) => [$urutan[$p['tingkat']], $p['lokasi']])->values();
    }

    private function stokMinus(Collection $lokasi): Collection
    {
        $minus = DB::table('stok_saldo')
            ->join('bahan_baku', 'bahan_baku.id', '=', 'stok_saldo.bahan_baku_id')
            ->whereIn('stok_saldo.cabang_id', $lokasi->pluck('id'))
            ->where('stok_saldo.saldo', '<', 0)
            ->orderBy('bahan_baku.nama')
            ->get(['stok_saldo.cabang_id', 'bahan_baku.nama'])
            ->groupBy('cabang_id');

        return $minus->map(fn (Collection $baris, int $id) => $this->buat(
            self::TINGKAT_TINGGI,
            $lokasi->firstWhere('id', $id),
            $baris->count().' bahan stoknya minus',
            $this->daftarNama($baris->pluck('nama')).'. Cek dengan stock opname.',
            route('admin-utama.stok', ['lokasi' => $id, 'status' => PantauanStokService::STATUS_MINUS], false)
        ))->values();
    }

    private function selisihKiriman(Collection $cabang): Collection
    {
        return Kiriman::query()
            ->whereIn('ke_cabang_id', $cabang->pluck('id'))
            ->where('status', Kiriman::STATUS_DICHECKLIST)
            ->where('sampai_pada', '>=', $this->awalPengamatan())
            ->orderBy('sampai_pada')
            ->get(['id', 'nomor', 'ke_cabang_id'])
            ->groupBy('ke_cabang_id')
            ->map(fn (Collection $daftar, int $id) => $this->buat(
                $daftar->count() >= self::SELISIH_KIRIMAN_TINGGI ? self::TINGKAT_TINGGI : self::TINGKAT_SEDANG,
                $cabang->firstWhere('id', $id),
                $daftar->count().' kiriman diterima dengan selisih',
                self::HARI_PENGAMATAN.' hari terakhir: '.$this->daftarNama($daftar->pluck('nomor')).'.',
                route('admin-utama.stok', ['lokasi' => $id], false)
            ))
            ->values();
    }

    private function rusakBerulang(Collection $lokasi): Collection
    {
        return BarangKeluar::query()
            ->rusak()
            ->whereIn('cabang_id', $lokasi->pluck('id'))
            ->where('status', BarangKeluar::STATUS_DISETUJUI)
            ->whereDate('tanggal', '>=', $this->awalPengamatan()->toDateString())
            ->orderByDesc('id')
            ->get(['id', 'cabang_id'])
            ->groupBy('cabang_id')
            ->filter(fn (Collection $daftar) => $daftar->count() >= self::RUSAK_BERULANG)
            ->map(fn (Collection $daftar, int $id) => $this->buat(
                $daftar->count() >= self::RUSAK_TINGGI ? self::TINGKAT_TINGGI : self::TINGKAT_SEDANG,
                $lokasi->firstWhere('id', $id),
                $daftar->count().' laporan barang rusak/terbuang',
                self::HARI_PENGAMATAN.' hari terakhir. Cek penyimpanan barang.',
                route('admin-utama.persetujuan.barang-keluar', $daftar->first()->id, false)
            ))
            ->values();
    }

    private function opnameSelisihBesar(Collection $lokasi): Collection
    {
        $terakhir = Opname::query()
            ->whereIn('cabang_id', $lokasi->pluck('id'))
            ->whereIn('status', [Opname::STATUS_DIKIRIM, Opname::STATUS_DISETUJUI])
            ->whereDate('tanggal', '>=', $this->awalPengamatan()->toDateString())
            ->orderByDesc('id')
            ->get(['id', 'nomor', 'cabang_id'])
            ->unique('cabang_id');

        return $terakhir->map(function (Opname $o) use ($lokasi) {
            $luar = OpnameDetail::query()
                ->where('opname_id', $o->id)
                ->whereNotNull('qty_fisik')
                ->with('bahanBaku:id,nama,toleransi_persen')
                ->get()
                ->filter->lewatToleransi();

            if ($luar->isEmpty()) {
                return null;
            }

            return $this->buat(
                $luar->count() >= self::OPNAME_LUAR_TINGGI ? self::TINGKAT_TINGGI : self::TINGKAT_SEDANG,
                $lokasi->firstWhere('id', $o->cabang_id),
                'Opname '.$o->nomor.': '.$luar->count().' bahan selisih di luar toleransi',
                $this->daftarNama($luar->pluck('bahanBaku.nama')).'.',
                route('admin-utama.persetujuan.opname', $o, false)
            );
        })->filter()->values();
    }

    private function opnameTerkunciLama(Collection $lokasi): Collection
    {
        return Opname::query()
            ->whereIn('cabang_id', $lokasi->pluck('id'))
            ->belumSelesai()
            ->where('created_at', '<', now()->subHours(self::OPNAME_TERKUNCI_JAM))
            ->get()
            ->map(fn (Opname $o) => $this->buat(
                self::TINGKAT_SEDANG,
                $lokasi->firstWhere('id', $o->cabang_id),
                'Stok dikunci opname sejak '.$o->created_at->translatedFormat('j M, H.i'),
                $o->status === Opname::STATUS_DIKIRIM
                    ? $o->nomor.' menunggu keputusan Anda. Transaksi lokasi ini tertahan.'
                    : $o->nomor.' belum selesai dihitung. Transaksi lokasi ini tertahan.',
                $o->status === Opname::STATUS_DIKIRIM ? route('admin-utama.persetujuan.opname', $o, false) : null
            ));
    }

    /** Pemakaian pada hari penjualan terakhir dibandingkan rata-rata HARI_PEMBANDING hari sebelumnya. */
    private function pemakaianMelonjak(Collection $cabang): Collection
    {
        return $cabang->map(function (Cabang $c) {
            $hariTerakhir = PenjualanHarian::query()->diLokasi($c->id)->max('tanggal');

            if (! $hariTerakhir) {
                return null;
            }

            $hariTerakhir = Carbon::parse($hariTerakhir)->toDateString();
            $awal = Carbon::parse($hariTerakhir)->subDays(self::HARI_PEMBANDING)->toDateString();

            $pemakaian = DB::table('stok_mutasi')
                ->join('barang_keluar', 'barang_keluar.id', '=', 'stok_mutasi.dokumen_id')
                ->join('bahan_baku', 'bahan_baku.id', '=', 'stok_mutasi.bahan_baku_id')
                ->where('stok_mutasi.dokumen_tipe', BarangKeluar::DOKUMEN_TIPE)
                ->where('stok_mutasi.jenis', StokMutasi::JENIS_PAKAI_DAPUR)
                ->where('stok_mutasi.status', StokMutasi::STATUS_SAH)
                ->where('stok_mutasi.cabang_id', $c->id)
                ->whereDate('barang_keluar.tanggal', '>=', $awal)
                ->whereDate('barang_keluar.tanggal', '<=', $hariTerakhir)
                ->groupBy('bahan_baku.nama', 'barang_keluar.tanggal')
                ->selectRaw('bahan_baku.nama as nama, barang_keluar.tanggal as tanggal, -sum(stok_mutasi.jumlah) as pakai')
                ->get()
                ->map(fn ($r) => (object) ['nama' => $r->nama, 'tanggal' => substr((string) $r->tanggal, 0, 10), 'pakai' => (float) $r->pakai]);

            if ($pemakaian->pluck('tanggal')->unique()->count() < self::MIN_HARI_DATA) {
                return null;
            }

            $melonjak = $pemakaian->groupBy('nama')->filter(function (Collection $baris) use ($hariTerakhir) {
                $hari = (float) $baris->where('tanggal', $hariTerakhir)->sum('pakai');
                $sebelum = (float) $baris->where('tanggal', '<', $hariTerakhir)->sum('pakai') / self::HARI_PEMBANDING;

                return $sebelum > 0 && $hari > $sebelum * self::LONJAKAN_KALI;
            })->keys();

            if ($melonjak->isEmpty()) {
                return null;
            }

            return $this->buat(
                self::TINGKAT_SEDANG,
                $c,
                'Pemakaian '.$melonjak->count().' bahan melonjak',
                $this->daftarNama($melonjak).' pada '.Carbon::parse($hariTerakhir)->translatedFormat('j M')
                    .' lebih dari '.self::LONJAKAN_KALI.'x rata-rata '.self::HARI_PEMBANDING.' hari sebelumnya.',
                route('admin-utama.stok', ['lokasi' => $c->id], false)
            );
        })->filter()->values();
    }

    private function penjualanBelumDicatat(Collection $cabang): Collection
    {
        $kemarin = now()->subDay()->toDateString();
        $tigaHari = now()->subDays(3)->toDateString();

        $tercatat = PenjualanHarian::query()
            ->whereIn('cabang_id', $cabang->pluck('id'))
            ->whereDate('tanggal', '>=', $tigaHari)
            ->whereDate('tanggal', '<=', $kemarin)
            ->get(['cabang_id', 'tanggal'])
            ->groupBy('cabang_id');

        return $cabang->map(function (Cabang $c) use ($tercatat, $kemarin) {
            $tanggal = ($tercatat[$c->id] ?? collect())->map(fn ($p) => $p->tanggal->toDateString());

            // Cabang yang baru dibuat hari ini belum punya hari penjualan untuk diperiksa.
            if ($tanggal->contains($kemarin) || $c->created_at?->toDateString() > $kemarin) {
                return null;
            }

            return $this->buat(
                $tanggal->isEmpty() ? self::TINGKAT_TINGGI : self::TINGKAT_SEDANG,
                $c,
                $tanggal->isEmpty() ? 'Penjualan 3 hari terakhir belum dicatat' : 'Penjualan kemarin belum dicatat',
                'Stok sistem jadi lebih banyak dari fisik.',
                null
            );
        })->filter()->values();
    }

    private function buat(string $tingkat, Cabang $lokasi, string $judul, string $keterangan, ?string $tautan): array
    {
        return [
            'tingkat'    => $tingkat,
            'lokasi'     => $lokasi->nama,
            'lokasi_id'  => $lokasi->id,
            'judul'      => $judul,
            'keterangan' => $keterangan,
            'tautan'     => $tautan,
        ];
    }

    private function daftarNama(Collection $nama): string
    {
        $tampil = $nama->take(self::MAKS_NAMA)->implode(', ');
        $sisa = $nama->count() - self::MAKS_NAMA;

        return $sisa > 0 ? $tampil." dan {$sisa} lainnya" : $tampil;
    }

    private function awalPengamatan(): Carbon
    {
        return now()->subDays(self::HARI_PENGAMATAN)->startOfDay();
    }
}
