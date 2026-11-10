<?php

namespace App\Http\Controllers\Cabang;

use App\Exceptions\StokTidakCukup;
use App\Http\Controllers\Concerns\UnduhPeriode;
use App\Http\Requests\Cabang\KirimBarangRequest;
use App\Http\Requests\Pusat\KirimanRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kiriman;
use App\Services\CetakDokumenService;
use App\Services\EksporDaftarService;
use App\Services\KirimanService;
use App\Support\Angka;
use App\Support\DokumenPdf;
use App\Support\Periode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Kiriman dari cabang ke cabang lain atau retur ke Gudang Pusat, tanpa persetujuan, beserta surat jalannya. */
class KirimBarangController extends CabangController
{
    use UnduhPeriode;

    public function __construct(private KirimanService $layanan)
    {
    }

    /** Excel kiriman keluar cabang per periode, satu baris per barang. */
    public function unduh(Request $request, EksporDaftarService $ekspor): View|StreamedResponse
    {
        $lokasi = $this->lokasi($request);

        return $this->unduhPerPeriode($request, 'Kiriman dari '.$lokasi->nama, 'cabang.kirim-barang.unduh', route('cabang.kirim-barang'),
            fn (Periode $periode) => $ekspor->kiriman($lokasi, $periode, masuk: false));
    }

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $daftar = Kiriman::query()
            ->dari($lokasi->id)
            ->with('keCabang:id,nama')
            ->withCount('detail')
            ->orderByDesc('dikirim_pada')
            ->orderByDesc('id')
            ->paginate(20);

        return view('cabang.kirim-barang.index', ['daftar' => $daftar]);
    }

    public function create(Request $request): View
    {
        $lokasi = $this->lokasi($request);
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->pluck('saldo', 'bahan_baku_id');

        $bahan = BahanBaku::query()
            ->aktif()
            ->with('satuanBeli:id,nama')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_beli_id', 'isi_per_satuan_beli']);

        $info = fn (BahanBaku $b) => 'Stok '.$lokasi->nama.' '.Angka::tampil($b->keSatuanBeli((float) ($saldo[$b->id] ?? 0))).' '.$b->satuanBeli->nama;
        $idTercantum = collect(old('baris', []))->pluck('bahan_baku_id')->filter()->map(fn ($id) => (int) $id);

        return view('cabang.kirim-barang.form', [
            'lokasi'      => $lokasi,
            'baris'       => [['bahan_baku_id' => null, 'qty' => null]],
            'pilihanTujuan' => Cabang::query()->aktif()->whereKeyNot($lokasi->id)->orderByDesc('jenis')->orderBy('nama')->get(['id', 'nama', 'jenis'])
                ->mapWithKeys(fn (Cabang $c) => [$c->id => $c->isPusat() ? $c->nama.' (retur)' : $c->nama])
                ->all(),
            'infoBahan'   => $bahan->whereIn('id', $idTercantum)->mapWithKeys(fn (BahanBaku $b) => [
                $b->id => ['nama' => $b->nama, 'satuan' => $b->satuanBeli->nama, 'info' => $info($b)],
            ]),
            'dataBahan'   => $bahan->map(fn (BahanBaku $b) => [
                'id'     => $b->id,
                'nama'   => $b->nama,
                'lain'   => (string) $b->nama_lain,
                'satuan' => $b->satuanBeli->nama,
                'info'   => $info($b),
                'kurang' => (float) ($saldo[$b->id] ?? 0) <= 0,
            ])->values(),
            'maksCatatan' => KirimanRequest::MAKS_CATATAN,
        ]);
    }

    public function store(KirimBarangRequest $request): RedirectResponse
    {
        $lokasi = $this->lokasi($request);
        $tujuan = Cabang::query()->findOrFail($request->integer('ke_cabang_id'));

        try {
            $kiriman = $this->layanan->kirimLangsung($lokasi, $tujuan, $request->user(), $request->jumlah(), $request->validated('catatan'));
        } catch (StokTidakCukup $e) {
            return back()->withInput()->with('galat', $e->pesanDi($lokasi->nama));
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()
            ->route('cabang.kirim-barang.lihat', $kiriman)
            ->with('sukses', "Kiriman {$kiriman->nomor} ke {$tujuan->nama} tercatat. Stok {$lokasi->nama} sudah dikurangi. Cetak surat jalan untuk dibawa bersama barang.");
    }

    public function show(Request $request, Kiriman $kiriman): View
    {
        $this->pastikanPengirim($request, $kiriman);

        $kiriman->load(['keCabang:id,nama', 'pengirim:id,nama', 'detail.bahanBaku.satuanBeli', 'detail.pemeriksa:id,nama']);

        return view('kiriman.lihat', ['kiriman' => $kiriman, 'rute' => 'cabang.kirim-barang', 'judulDaftar' => 'Kirim barang']);
    }

    public function suratJalan(Request $request, Kiriman $kiriman, CetakDokumenService $cetak): Response
    {
        $this->pastikanPengirim($request, $kiriman);

        return DokumenPdf::unduh($cetak->suratJalan($kiriman, $request->user()), $cetak->namaFile('surat jalan', $kiriman->nomor));
    }

    private function pastikanPengirim(Request $request, Kiriman $kiriman): void
    {
        abort_unless((int) $kiriman->dari_cabang_id === $this->lokasi($request)->id, 404);
    }
}
