<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\IsiStokRequest;
use App\Http\Requests\TambahStokRequest;
use App\Http\Requests\UbahStokRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\StokMutasi;
use App\Services\EksporDaftarService;
use App\Services\IsiStokService;
use App\Services\KontrolSistemService;
use App\Services\LembarOpnameService;
use App\Services\PantauanStokService;
use App\Services\PenyesuaianStokService;
use App\Services\RiwayatStokService;
use App\Services\StokService;
use App\Services\TambahStokService;
use App\Support\Angka;
use App\Support\BukuExcel;
use App\Support\DokumenPdf;
use App\Support\Periode;
use App\Support\TautanDokumen;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman pantauan stok, intip bahan, riwayat mutasi per bahan, riwayat masuk/keluar & pemakaian harian,
 * tambah stok langsung, isi stok banyak bahan habis/menipis, ubah stok langsung (penyesuaian), dan lembar stock opname PDF,
 * untuk Admin Utama (pilih lokasi) dan admin (lokasi sendiri).
 * Controller pemakai menyediakan lokasiPantauan(Request), properti $rute, bolehPilihLokasi(), dan aksiIntip().
 */
trait PantauStok
{
    use PerbaruiStokExcel;

    /** Jumlah mutasi terakhir yang ditampilkan saat intip bahan. */
    protected int $mutasiIntip = 5;

    /** Tab halaman riwayat (?tampil=). */
    public const TAMPIL_HARI = 'hari';
    public const TAMPIL_TRANSAKSI = 'transaksi';
    public const TAMPIL_HARIAN = 'harian';

    abstract protected function lokasiPantauan(Request $request): Cabang;

    protected function bolehPilihLokasi(): bool
    {
        return false;
    }

    /**
     * Boleh membuka aksi stok ini (bagian akhir nama route, mis. 'tambah', 'ubah', 'excel', 'isi')?
     * Admin Cabang tidak punya route tambah/ubah/excel; untuk Admin Pusat diatur di Hak Akses.
     */
    protected function bolehAksiStok(Request $request, string $aksi): bool
    {
        $nama = $this->rute.'.'.$aksi;

        return Route::has($nama) && app(KontrolSistemService::class)->bolehRute($request->user(), $nama, 'POST');
    }

    /** @return array{tambah: bool, ubah: bool, excel: bool, isi: bool} */
    protected function aksiStok(Request $request): array
    {
        return collect(['tambah', 'ubah', 'excel', 'isi'])
            ->mapWithKeys(fn (string $aksi) => [$aksi => $this->bolehAksiStok($request, $aksi)])
            ->all();
    }

    /** Tombol tambahan di intip bahan, mis. ['label' => 'Minta Barang', 'url' => ...], atau null. */
    protected function aksiIntip(Request $request): ?array
    {
        return null;
    }

    public function index(
        Request $request,
        PantauanStokService $pantauan,
        IsiStokService $isi,
    ): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $semua = $pantauan->daftar($lokasi);
        $modeIsi = $isi->mode($request->user());
        $aksiStok = $this->aksiStok($request);

        $filter = [
            'lampu'    => array_key_exists((string) $request->query('lampu'), PantauanStokService::TAMPILAN_LAMPU) ? $request->query('lampu') : '',
            'status'   => array_key_exists((string) $request->query('status'), PantauanStokService::TAMPILAN_STATUS) ? $request->query('status') : '',
            'kategori' => (int) $request->query('kategori') ?: null,
            'cari'     => trim((string) $request->query('cari')),
        ];

        $urutanStatus = array_flip(array_keys(PantauanStokService::TAMPILAN_STATUS));

        $daftar = $semua
            ->when($filter['lampu'] !== '', fn (Collection $c) => $c->where('lampu', $filter['lampu']))
            ->when($filter['status'] !== '', fn (Collection $c) => $c->where('status', $filter['status']))
            ->when($filter['kategori'], fn (Collection $c, int $id) => $c->filter(fn ($r) => $r['bahan']->kategori_id === $id))
            ->when($filter['cari'] !== '', fn (Collection $c) => $c->filter(
                fn ($r) => str_contains(mb_strtolower($r['bahan']->nama), mb_strtolower($filter['cari']))
            ))
            ->sortBy(fn ($r) => [$urutanStatus[$r['status']], $r['cukup_hari'] ?? PHP_INT_MAX, $r['bahan']->nama])
            ->values();

        $kelompok = PantauanStokService::kelompokkanPerKategori($daftar);
        $parameterLokasi = $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [];

        return view('stok.index', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'kelompok'        => $kelompok,
            'bukaSemua'       => $kelompok->count() === 1 || collect($filter)->contains(fn ($v) => $v !== '' && $v !== null),
            'jumlahPerLampu'  => PantauanStokService::hitungPerLampu($semua),
            'filter'          => $filter,
            'pilihanLampu'    => PantauanStokService::TAMPILAN_LAMPU,
            'pilihanStatus'   => PantauanStokService::TAMPILAN_STATUS,
            'pilihanKategori' => Kategori::query()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id'),
            'pilihanLokasi'   => $this->bolehPilihLokasi()
                ? Cabang::query()->aktif()->orderByDesc('jenis')->orderBy('nama')->pluck('nama', 'id')
                : null,
            'parameterLokasi' => $parameterLokasi,
            'modeIsi'         => $modeIsi,
            'pilihanIsi'      => $aksiStok['isi'] ? $isi->pilihan($lokasi, $modeIsi, $semua) : [],
            'aksiStok'        => $aksiStok,
        ]);
    }

    /** Ringkasan satu bahan untuk popup intip: angka utama dan mutasi terakhir. */
    public function intip(
        Request $request,
        PantauanStokService $pantauan,
        BahanBaku $bahanBaku,
    ): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $bahanBaku->load(['satuanBeli:id,nama', 'satuanDasar:id,nama']);

        return view('stok.intip', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'bahan'           => $bahanBaku,
            'kondisi'         => $pantauan->daftar($lokasi, [$bahanBaku->id])->first(),
            'mutasi'          => StokMutasi::query()
                ->where('cabang_id', $lokasi->id)
                ->where('bahan_baku_id', $bahanBaku->id)
                ->where('status', '!=', StokMutasi::STATUS_BATAL)
                ->orderByDesc('id')
                ->limit($this->mutasiIntip)
                ->get(),
            'aksi'            => $this->aksiIntip($request),
            'aksiStok'        => $this->aksiStok($request),
            'parameterLokasi' => $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [],
        ]);
    }

    /** Form tambah stok langsung (popup). ?bahan= memilih bahan lebih dulu. */
    public function tambah(Request $request): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $terpilih = BahanBaku::query()->aktif()->with('satuanBeli:id,nama')->find((int) old('bahan_baku_id', $request->query('bahan')));

        return view('stok.tambah', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'bahan'           => $terpilih,
            'dataBahan'       => BahanBaku::query()->aktif()->with('satuanBeli:id,nama')->orderBy('nama')
                ->get(['id', 'nama', 'nama_lain', 'satuan_beli_id'])
                ->map(fn (BahanBaku $b) => ['id' => $b->id, 'nama' => $b->nama, 'lain' => (string) $b->nama_lain, 'satuan' => $b->satuanBeli->nama])
                ->values(),
            'saldo'           => $terpilih ? app(StokService::class)->saldo($lokasi->id, $terpilih->id) : null,
            'maksKeterangan'  => TambahStokRequest::MAKS_KETERANGAN,
            'parameterLokasi' => $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [],
        ]);
    }

    public function simpanTambah(TambahStokRequest $request, TambahStokService $layanan): RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);
        $bahan = BahanBaku::query()->with('satuanBeli:id,nama')->findOrFail($request->integer('bahan_baku_id'));
        $kembali = redirect()->route($this->rute, $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : []);

        try {
            $dokumen = $layanan->tambah(
                $request->user(), $lokasi, $bahan, (float) $request->validated('jumlah'),
                $request->validated('keterangan'),
            );
        } catch (DomainException $e) {
            return $kembali->with('galat', $e->getMessage());
        }

        return $kembali->with('sukses', "Stok {$bahan->nama} di {$lokasi->nama} bertambah "
            .Angka::tampil($bahan->keSatuanBeli($dokumen->jumlah)).' '.$bahan->satuanBeli->nama." ({$dokumen->nomor}).");
    }

    /**
     * Form isi stok (popup) untuk bahan habis/menipis yang dicentang (?bahan[]=); tanpa pilihan = semua yang habis/menipis.
     * Jumlah terisi usulan minimum + persen dan bisa diubah sebelum dikirim.
     */
    public function isi(Request $request, IsiStokService $isi): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $mode = $isi->mode($request->user());
        $pilih = collect((array) $request->query('bahan'))->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $daftar = $isi->daftar($lokasi, $mode, $pilih === [] ? null : $pilih);

        return view('stok.isi', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'mode'            => $mode,
            'daftar'          => $daftar->reject(fn (array $r) => $r['diminta'])->values(),
            'sudahDiminta'    => $daftar->filter(fn (array $r) => $r['diminta'])->map(fn (array $r) => $r['bahan']->nama)->values(),
            'persen'          => $isi->persen(),
            'maksKeterangan'  => TambahStokRequest::MAKS_KETERANGAN,
            'parameterLokasi' => $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [],
        ]);
    }

    public function simpanIsi(IsiStokRequest $request, IsiStokService $isi): RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);
        $kembali = redirect()->route($this->rute, $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : []);
        $baris = array_values($request->validated('baris'));

        try {
            if ($request->modeTambah()) {
                $dokumen = $isi->tambah($request->user(), $lokasi, $baris, $request->validated('keterangan'));

                return $kembali->with('sukses', "Stok {$dokumen->count()} bahan di {$lokasi->nama} bertambah. Tercatat di riwayat.");
            }

            $permintaan = $isi->minta($request->user(), $lokasi, $baris);
        } catch (DomainException $e) {
            return $kembali->with('galat', $e->getMessage());
        }

        return $kembali->with('sukses', "Permintaan {$permintaan->nomor} terkirim ke Admin Utama: ".count($baris).' bahan.');
    }

    /** Form ubah stok satu bahan (popup): stok disamakan dengan jumlah sebenarnya. */
    public function ubah(Request $request, StokService $stok, BahanBaku $bahanBaku): View|RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);
        $parameterLokasi = $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [];

        if (! $bahanBaku->aktif) {
            return redirect()->route($this->rute, $parameterLokasi)->with('galat', "Bahan {$bahanBaku->nama} sedang nonaktif.");
        }

        $bahanBaku->load('satuanBeli:id,nama');
        $saldo = round((float) old('stok_lama', $stok->saldo($lokasi->id, $bahanBaku->id)), 3);

        return view('stok.ubah', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'bahan'           => $bahanBaku,
            'saldo'           => $saldo,
            'maksKeterangan'  => TambahStokRequest::MAKS_KETERANGAN,
            'parameterLokasi' => $parameterLokasi,
        ]);
    }

    public function simpanUbah(UbahStokRequest $request, PenyesuaianStokService $layanan, BahanBaku $bahanBaku): RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);
        $kembali = redirect()->route($this->rute, $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : []);

        try {
            $dokumen = $layanan->sesuaikan(
                $request->user(), $lokasi, $bahanBaku, (float) $request->validated('stok_baru'), (float) $request->validated('stok_lama'),
                $request->validated('keterangan'),
            );
        } catch (DomainException $e) {
            return $kembali->with('galat', $e->getMessage());
        }

        return $kembali->with('sukses', "Stok {$bahanBaku->nama} di {$lokasi->nama} diubah dari "
            .$layanan->teksJumlah($bahanBaku, (float) $dokumen->stok_lama).' menjadi '.$layanan->teksJumlah($bahanBaku, (float) $dokumen->stok_baru)." ({$dokumen->nomor}).");
    }

    /** Riwayat stok: per hari (bawaan), masuk & keluar per transaksi (?tampil=transaksi), atau pemakaian harian (?tampil=harian). */
    public function mutasi(Request $request, RiwayatStokService $riwayat): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $saringan = $this->saringanMutasi($request);
        $tampil = $saringan['tampil'];

        $mutasi = $tampil === self::TAMPIL_TRANSAKSI ? $riwayat->transaksi($lokasi, $saringan['periode'], $saringan['filter']) : null;
        $perHari = $tampil === self::TAMPIL_HARI
            ? $riwayat->perHari($lokasi, $saringan['tanggal'], $saringan['filter'], $saringan['semua'])
            : collect();

        return view('stok.mutasi', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'tampil'          => $tampil,
            'periode'         => $saringan['periode'],
            'tanggal'         => $saringan['tanggal'],
            'semua'           => $saringan['semua'],
            'filter'          => $saringan['filter'],
            'perHari'         => $perHari,
            'mutasi'          => $mutasi,
            'dokumen'         => $mutasi ? TautanDokumen::untuk($request->user(), $mutasi->getCollection()) : [],
            'pemakaian'       => $tampil === self::TAMPIL_HARIAN ? $riwayat->harian($lokasi, $saringan['periode'], $saringan['filter']) : collect(),
            'pilihanArah'     => RiwayatStokService::LABEL_ARAH,
            'pilihanJenis'    => StokMutasi::LABEL_JENIS,
            'pilihanKategori' => Kategori::query()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id'),
            'parameterLokasi' => $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [],
        ]);
    }

    /** Excel riwayat sesuai tab dan saringan halaman riwayat. */
    public function unduhMutasi(Request $request, EksporDaftarService $ekspor): StreamedResponse
    {
        $lokasi = $this->lokasiPantauan($request);
        $saringan = $this->saringanMutasi($request);
        $periode = $saringan['periode'];

        if ($saringan['tampil'] === self::TAMPIL_HARI) {
            return BukuExcel::unduh(
                $ekspor->stokPerHari($lokasi, $saringan['tanggal'], $saringan['filter'], $saringan['semua']),
                BukuExcel::namaBerkas('stok per hari', $lokasi->nama, $saringan['tanggal']->toDateString())
            );
        }

        $harian = $saringan['tampil'] === self::TAMPIL_HARIAN;

        return BukuExcel::unduh(
            $ekspor->riwayatStok($lokasi, $periode, $saringan['filter'], $harian, $request->user()),
            BukuExcel::namaBerkas($harian ? 'pemakaian harian' : 'riwayat stok', $lokasi->nama, $periode->dariTanggal(), $periode->sampaiTanggal())
        );
    }

    /** Excel stok saat ini satu lokasi. */
    public function unduh(Request $request, EksporDaftarService $ekspor): StreamedResponse
    {
        $lokasi = $this->lokasiPantauan($request);

        return BukuExcel::unduh($ekspor->stok($lokasi), BukuExcel::namaBerkas('stok', $lokasi->nama, now()->toDateString()));
    }

    /**
     * Saringan halaman riwayat. Tanpa ?tampil= dibuka tab per hari, kecuali alamat lama yang membawa periode/arah/jenis (tab transaksi).
     * Tanggal per hari: ?tanggal=, bawaan hari ini, tidak boleh setelah hari ini.
     *
     * @return array{tampil: string, periode: Periode, tanggal: Carbon, semua: bool, filter: array{arah: string, jenis: string, kategori: ?int, cari: string}}
     */
    private function saringanMutasi(Request $request): array
    {
        $tampil = (string) $request->query('tampil');
        if (! in_array($tampil, [self::TAMPIL_HARI, self::TAMPIL_TRANSAKSI, self::TAMPIL_HARIAN], true)) {
            $tampil = collect(['dari', 'sampai', 'arah', 'jenis'])->contains(fn ($k) => $request->filled($k)) ? self::TAMPIL_TRANSAKSI : self::TAMPIL_HARI;
        }

        $periode = $request->filled('dari') || $request->filled('sampai')
            ? Periode::dariRequest($request)
            : Periode::antara(now()->subDays(6), now());

        $tanggal = rescue(fn () => Carbon::createFromFormat('Y-m-d', (string) $request->query('tanggal'))->startOfDay(), null, false);
        if (! $tanggal || $tanggal->isAfter(today())) {
            $tanggal = today();
        }

        return [
            'tampil'  => $tampil,
            'periode' => $periode,
            'tanggal' => $tanggal,
            'semua'   => $request->boolean('semua'),
            'filter'  => [
                'arah'     => array_key_exists((string) $request->query('arah'), RiwayatStokService::LABEL_ARAH) ? $request->query('arah') : '',
                'jenis'    => array_key_exists((string) $request->query('jenis'), StokMutasi::LABEL_JENIS) ? $request->query('jenis') : '',
                'kategori' => (int) $request->query('kategori') ?: null,
                'cari'     => trim((string) $request->query('cari')),
            ],
        ];
    }

    /** Pilih kategori lembar stock opname (popup). */
    public function lembarOpname(Request $request): View
    {
        $lokasi = $this->lokasiPantauan($request);

        return view('stok.lembar-opname', [
            'lokasi'          => $lokasi,
            'rute'            => $this->rute,
            'pilihanKategori' => Kategori::query()->aktif()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id'),
            'parameterLokasi' => $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [],
        ]);
    }

    public function unduhLembarOpname(Request $request, LembarOpnameService $lembar): Response
    {
        $lokasi = $this->lokasiPantauan($request);
        $kategori = Kategori::query()->find((int) $request->query('kategori'));

        return DokumenPdf::unduh($lembar->buat($lokasi, $kategori, $request->user()), $lembar->namaFile($lokasi, $kategori));
    }

    public function riwayat(
        Request $request,
        StokService $stok,
        PantauanStokService $pantauan,
        BahanBaku $bahanBaku,
    ): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $bahanBaku->load(['satuanBeli:id,nama', 'satuanDasar:id,nama']);

        $mutasi = StokMutasi::query()
            ->where('cabang_id', $lokasi->id)
            ->where('bahan_baku_id', $bahanBaku->id)
            ->where('status', '!=', StokMutasi::STATUS_BATAL)
            ->with('pembuat:id,nama')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Saldo setelah setiap mutasi sah, dihitung mundur dari saldo sekarang.
        $saldo = $stok->saldo($lokasi->id, $bahanBaku->id);
        $pertama = $mutasi->first();
        if ($pertama) {
            $saldo -= (float) StokMutasi::query()
                ->where('cabang_id', $lokasi->id)
                ->where('bahan_baku_id', $bahanBaku->id)
                ->sah()
                ->where('id', '>', $pertama->id)
                ->sum('jumlah');
        }

        $saldoSetelah = [];
        foreach ($mutasi as $m) {
            if ($m->status !== StokMutasi::STATUS_SAH) {
                continue;
            }
            $saldoSetelah[$m->id] = round($saldo, 3);
            $saldo -= (float) $m->jumlah;
        }

        return view('stok.riwayat', [
            'lokasi'       => $lokasi,
            'rute'         => $this->rute,
            'bahan'        => $bahanBaku,
            'mutasi'       => $mutasi,
            'saldoSetelah' => $saldoSetelah,
            'saldoSekarang' => $stok->saldo($lokasi->id, $bahanBaku->id),
            'kondisi'      => $pantauan->daftar($lokasi, [$bahanBaku->id])->first(),
            'dokumen'      => TautanDokumen::untuk($request->user(), $mutasi->getCollection()),
            'aksiStok'     => $this->aksiStok($request),
            'parameterLokasi' => $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [],
        ]);
    }
}
