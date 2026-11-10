<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Requests\Pusat\BarangMasukRequest;
use App\Models\BahanBaku;
use App\Models\BarangMasuk;
use App\Models\BarangMasukDetail;
use App\Services\BarangMasukService;
use App\Services\CetakTransaksiService;
use App\Support\Angka;
use App\Support\DokumenPdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class BarangMasukController extends PusatController
{
    public function __construct(
        private BarangMasukService $layanan,
    ) {}

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);
        $status = $request->query('status');

        if (! is_string($status) || ! array_key_exists($status, BarangMasuk::TAMPILAN_STATUS)) {
            $status = 'semua';
        }

        $daftar = BarangMasuk::query()
            ->diLokasi($lokasi->id)
            ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
            ->withCount('detail')
            ->with('pencatat:id,nama')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('pusat.barang-masuk.index', [
            'daftar' => $daftar,
            'status' => $status,
            'pilihanStatus' => BarangMasuk::TAMPILAN_STATUS,
            'perluDiperbaiki' => BarangMasuk::query()->diLokasi($lokasi->id)->where('status', BarangMasuk::STATUS_REVISI)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->lokasi($request);

        return $this->form(null, [['bahan_baku_id' => null, 'qty' => '', 'keterangan' => null]]);
    }

    public function store(BarangMasukRequest $request): RedirectResponse
    {
        $dokumen = $this->layanan->catat($request->user(), $this->lokasi($request), $request->validated());

        return redirect()
            ->route('pusat.barang-masuk.lihat', $dokumen)
            ->with('sukses', "Barang masuk {$dokumen->nomor} tersimpan. Stok bertambah setelah disetujui Admin Utama.");
    }

    /** PDF bukti barang masuk. */
    public function pdf(Request $request, BarangMasuk $barangMasuk, CetakTransaksiService $cetak): Response
    {
        $this->pastikanMilikLokasi($request, $barangMasuk);

        return DokumenPdf::unduh($cetak->barangMasuk($barangMasuk, $request->user()), $cetak->namaFile('bukti barang masuk', $barangMasuk->nomor));
    }

    public function show(Request $request, BarangMasuk $barangMasuk): View
    {
        $this->pastikanMilikLokasi($request, $barangMasuk);

        $barangMasuk->load([
            'pencatat:id,nama',
            'detail.bahanBaku.satuanBeli',
            'detail.bahanBaku.satuanDasar',
            'persetujuan.oleh:id,nama',
        ]);

        return view('pusat.barang-masuk.lihat', ['dokumen' => $barangMasuk]);
    }

    public function edit(Request $request, BarangMasuk $barangMasuk): View|RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $barangMasuk);

        if (! $barangMasuk->bisaDiubah()) {
            return redirect()->route('pusat.barang-masuk.lihat', $barangMasuk)
                ->with('galat', 'Barang masuk ini sudah diputuskan Admin Utama, jadi tidak bisa diubah.');
        }

        $baris = $barangMasuk->detail()
            ->with('bahanBaku:id,isi_per_satuan_beli')
            ->get()
            ->map(fn (BarangMasukDetail $d) => [
                'bahan_baku_id' => $d->bahan_baku_id,
                'qty' => Angka::isian($d->bahanBaku->keSatuanBeli($d->qty)),
                'keterangan' => $d->keterangan,
            ])
            ->all();

        return $this->form($barangMasuk, $baris);
    }

    public function update(BarangMasukRequest $request, BarangMasuk $barangMasuk): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $barangMasuk);

        try {
            $this->layanan->ubah($barangMasuk, $request->user(), $request->validated());
        } catch (DomainException $e) {
            return redirect()->route('pusat.barang-masuk.lihat', $barangMasuk)->with('galat', $e->getMessage());
        }

        return redirect()
            ->route('pusat.barang-masuk.lihat', $barangMasuk)
            ->with('sukses', "Perubahan {$barangMasuk->nomor} tersimpan. Stok bertambah setelah disetujui Admin Utama.");
    }

    public function batalkan(Request $request, BarangMasuk $barangMasuk): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $barangMasuk);

        try {
            $this->layanan->batalkan($barangMasuk);
        } catch (DomainException $e) {
            return redirect()->route('pusat.barang-masuk.lihat', $barangMasuk)->with('galat', $e->getMessage());
        }

        return redirect()
            ->route('pusat.barang-masuk')
            ->with('sukses', "Barang masuk {$barangMasuk->nomor} dibatalkan. Stok tidak berubah.");
    }

    private function form(?BarangMasuk $dokumen, array $baris): View
    {
        $bahan = BahanBaku::query()
            ->aktif()
            ->with('satuanBeli:id,nama')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_beli_id']);

        // Nama dan satuan untuk baris yang sudah terisi (termasuk bahan yang sudah nonaktif).
        $idTercantum = collect(old('baris', $baris))->pluck('bahan_baku_id')->filter()->map(fn ($id) => (int) $id);
        $infoBahan = BahanBaku::query()
            ->whereKey($idTercantum->all())
            ->with('satuanBeli:id,nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_beli_id'])
            ->mapWithKeys(fn (BahanBaku $b) => [$b->id => ['nama' => $b->nama, 'satuan' => $b->satuanBeli->nama]]);

        return view('pusat.barang-masuk.form', [
            'dokumen' => $dokumen,
            'baris' => $baris,
            'infoBahan' => $infoBahan,
            'dataBahan' => $bahan->map(fn (BahanBaku $b) => [
                'id' => $b->id,
                'nama' => $b->nama,
                'lain' => (string) $b->nama_lain,
                'satuan' => $b->satuanBeli->nama,
            ])->values(),
            'hariIni' => now()->toDateString(),
            'maksKeterangan' => BarangMasukRequest::MAKS_KETERANGAN,
            'maksCatatan' => BarangMasukRequest::MAKS_CATATAN,
        ]);
    }

    private function pastikanMilikLokasi(Request $request, BarangMasuk $dokumen): void
    {
        abort_unless((int) $dokumen->cabang_id === $this->lokasi($request)->id, 404);
    }
}
