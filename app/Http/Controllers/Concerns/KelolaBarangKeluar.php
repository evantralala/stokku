<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\BarangKeluarRequest;
use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Services\BarangKeluarService;
use App\Services\CetakTransaksiService;
use App\Services\EksporDaftarService;
use App\Services\StokService;
use App\Support\Angka;
use App\Support\DokumenPdf;
use App\Support\Periode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman barang keluar yang sama untuk Admin Pusat dan Admin Cabang.
 * Controller pemakai menyediakan lokasi(Request) dan properti $rute (awalan nama route).
 */
trait KelolaBarangKeluar
{
    use UnduhPeriode;

    abstract protected function lokasi(Request $request): Cabang;

    /** Excel barang rusak lokasi ini per periode. */
    public function unduh(Request $request, EksporDaftarService $ekspor): View|StreamedResponse
    {
        $lokasi = $this->lokasi($request);

        return $this->unduhPerPeriode($request, 'Barang rusak '.$lokasi->nama, $this->rute.'.unduh', route($this->rute),
            fn (Periode $periode) => $ekspor->barangRusak($lokasi, $periode));
    }

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $daftar = BarangKeluar::query()
            ->diLokasi($lokasi->id)
            ->rusak()
            ->withCount('detail')
            ->with('pencatat:id,nama')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(20);

        return view('barang-keluar.index', [
            'daftar'          => $daftar,
            'rute'            => $this->rute,
            'lokasi'          => $lokasi,
            'perluDiperbaiki' => BarangKeluar::query()->diLokasi($lokasi->id)->rusak()->where('status', BarangKeluar::STATUS_REVISI)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, null, [['bahan_baku_id' => null, 'qty' => '', 'alasan' => null]]);
    }

    public function store(BarangKeluarRequest $request, BarangKeluarService $layanan): RedirectResponse
    {
        try {
            $dokumen = $layanan->catat($request->user(), $this->lokasi($request), $request->validated());
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()
            ->route($this->rute.'.lihat', $dokumen)
            ->with('sukses', "{$dokumen->nomor} tersimpan. Stok berkurang setelah disetujui Admin Utama.");
    }

    /** PDF berita acara barang rusak. */
    public function pdf(Request $request, BarangKeluar $barangKeluar, CetakTransaksiService $cetak): Response
    {
        $this->pastikanMilikLokasi($request, $barangKeluar);

        return DokumenPdf::unduh($cetak->barangRusak($barangKeluar, $request->user()), $cetak->namaFile('berita acara barang rusak', $barangKeluar->nomor));
    }

    public function show(Request $request, BarangKeluar $barangKeluar): View
    {
        $this->pastikanMilikLokasi($request, $barangKeluar);

        $barangKeluar->load([
            'pencatat:id,nama',
            'detail.bahanBaku.satuanBeli',
            'persetujuan.oleh:id,nama',
        ]);

        return view('barang-keluar.lihat', ['dokumen' => $barangKeluar, 'rute' => $this->rute]);
    }

    public function edit(Request $request, BarangKeluar $barangKeluar): View|RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $barangKeluar);

        if (! $barangKeluar->bisaDiubah()) {
            return redirect()->route($this->rute.'.lihat', $barangKeluar)
                ->with('galat', 'Dokumen ini sudah diputuskan Admin Utama, jadi tidak bisa diubah.');
        }

        $baris = $barangKeluar->detail()
            ->with('bahanBaku:id,isi_per_satuan_beli')
            ->get()
            ->map(fn (BarangKeluarDetail $d) => [
                'bahan_baku_id' => $d->bahan_baku_id,
                'qty'           => Angka::isian($d->bahanBaku->keSatuanBeli($d->qty)),
                'alasan'        => $d->penjelasan,
            ])
            ->all();

        return $this->form($request, $barangKeluar, $baris);
    }

    public function update(BarangKeluarRequest $request, BarangKeluarService $layanan, BarangKeluar $barangKeluar): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $barangKeluar);

        try {
            $layanan->ubah($barangKeluar, $request->user(), $request->validated());
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()
            ->route($this->rute.'.lihat', $barangKeluar)
            ->with('sukses', "Perubahan {$barangKeluar->nomor} tersimpan. Stok berkurang setelah disetujui Admin Utama.");
    }

    public function batalkan(Request $request, BarangKeluarService $layanan, BarangKeluar $barangKeluar): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $barangKeluar);

        try {
            $layanan->batalkan($barangKeluar);
        } catch (DomainException $e) {
            return redirect()->route($this->rute.'.lihat', $barangKeluar)->with('galat', $e->getMessage());
        }

        return redirect()->route($this->rute)->with('sukses', "{$barangKeluar->nomor} dibatalkan. Stok tidak berubah.");
    }

    private function form(Request $request, ?BarangKeluar $dokumen, array $baris): View
    {
        $lokasi = $this->lokasi($request);
        $stok = app(StokService::class);

        $bahan = BahanBaku::query()
            ->aktif()
            ->with('satuanBeli:id,nama')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_beli_id', 'isi_per_satuan_beli']);

        $infoStok = fn (BahanBaku $b) => 'Stok tercatat '
            .Angka::tampil($b->keSatuanBeli($stok->saldo($lokasi->id, $b->id))).' '.$b->satuanBeli->nama;

        $idTercantum = collect(old('baris', $baris))->pluck('bahan_baku_id')->filter()->map(fn ($id) => (int) $id);
        $infoBahan = $bahan->whereIn('id', $idTercantum)->mapWithKeys(fn (BahanBaku $b) => [
            $b->id => ['nama' => $b->nama, 'satuan' => $b->satuanBeli->nama, 'info' => $infoStok($b)],
        ]);

        return view('barang-keluar.form', [
            'dokumen'        => $dokumen,
            'rute'           => $this->rute,
            'lokasi'         => $lokasi,
            'baris'          => $baris,
            'infoBahan'      => $infoBahan,
            'dataBahan'      => $bahan->map(fn (BahanBaku $b) => [
                'id'     => $b->id,
                'nama'   => $b->nama,
                'lain'   => (string) $b->nama_lain,
                'satuan' => $b->satuanBeli->nama,
                'info'   => $infoStok($b),
            ])->values(),
            'hariIni'        => now()->toDateString(),
            'maksAlasan'     => BarangKeluarRequest::MAKS_ALASAN,
        ]);
    }

    private function pastikanMilikLokasi(Request $request, BarangKeluar $dokumen): void
    {
        abort_unless(
            (int) $dokumen->cabang_id === $this->lokasi($request)->id && $dokumen->jenis === BarangKeluar::JENIS_RUSAK,
            404
        );
    }
}
