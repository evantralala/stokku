<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\UnduhPeriode;
use App\Http\Requests\Cabang\PermintaanRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Services\CetakDokumenService;
use App\Services\EksporDaftarService;
use App\Services\PermintaanService;
use App\Support\Angka;
use App\Support\DokumenPdf;
use App\Support\Periode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PermintaanController extends CabangController
{
    use UnduhPeriode;

    public function __construct(private PermintaanService $layanan)
    {
    }

    /** Excel permintaan cabang per periode, satu baris per bahan. */
    public function unduh(Request $request, EksporDaftarService $ekspor): View|StreamedResponse
    {
        $lokasi = $this->lokasi($request);

        return $this->unduhPerPeriode($request, 'Permintaan '.$lokasi->nama, 'cabang.permintaan.unduh', route('cabang.permintaan'),
            fn (Periode $periode) => $ekspor->permintaan($lokasi, $periode));
    }

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $daftar = Permintaan::query()
            ->diLokasi($lokasi->id)
            ->withCount([
                'detail',
                'detail as detail_menunggu_count' => fn ($q) => $q->where('status', PermintaanDetail::STATUS_MENUNGGU),
            ])
            ->orderByDesc('diajukan_pada')
            ->orderByDesc('id')
            ->paginate(20);

        return view('cabang.permintaan.index', [
            'daftar'       => $daftar,
            'jumlahUsulan' => $this->layanan->usulan($lokasi)->count(),
        ]);
    }

    /** ?usulan=1 mengisi form dengan bahan yang stoknya di bawah minimum. */
    public function create(Request $request): View
    {
        $lokasi = $this->lokasi($request);
        $usulan = $this->layanan->usulan($lokasi);

        $baris = $request->boolean('usulan') && $usulan->isNotEmpty()
            ? $usulan->map(fn (array $u) => [
                'bahan_baku_id' => $u['bahan']->id,
                'qty'           => Angka::isian($u['qty_beli']),
                'alasan'        => 'Stok di bawah minimum',
            ])->all()
            : [['bahan_baku_id' => null, 'qty' => '', 'alasan' => null]];

        return $this->form($lokasi, $baris, $usulan->count());
    }

    public function store(PermintaanRequest $request): RedirectResponse
    {
        $permintaan = $this->layanan->ajukan($request->user(), $this->lokasi($request), $request->validated());

        return redirect()
            ->route('cabang.permintaan.lihat', $permintaan)
            ->with('sukses', "Permintaan {$permintaan->nomor} terkirim dan menunggu persetujuan Admin Utama.");
    }

    public function show(Request $request, Permintaan $permintaan): View
    {
        $this->pastikanMilikLokasi($request, $permintaan);

        $permintaan->load([
            'pengaju:id,nama',
            'detail.bahanBaku.satuanBeli',
            'detail.bahanBaku.satuanDasar',
        ]);

        return view('cabang.permintaan.lihat', ['permintaan' => $permintaan]);
    }

    public function pdf(Request $request, Permintaan $permintaan, CetakDokumenService $cetak): Response
    {
        $this->pastikanMilikLokasi($request, $permintaan);

        return DokumenPdf::unduh($cetak->permintaan($permintaan, $request->user()), $cetak->namaFile('permintaan', $permintaan->nomor));
    }

    public function batalkan(Request $request, Permintaan $permintaan): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $permintaan);

        try {
            $this->layanan->batalkan($permintaan);
        } catch (DomainException $e) {
            return redirect()->route('cabang.permintaan.lihat', $permintaan)->with('galat', $e->getMessage());
        }

        return redirect()->route('cabang.permintaan')->with('sukses', "Permintaan {$permintaan->nomor} dibatalkan.");
    }

    private function form(Cabang $lokasi, array $baris, int $jumlahUsulan): View
    {
        $bahan = BahanBaku::query()
            ->aktif()
            ->with('satuanBeli:id,nama')
            ->orderBy('nama')
            ->get(['id', 'nama', 'nama_lain', 'satuan_beli_id', 'isi_per_satuan_beli']);

        $kondisi = $this->layanan->kondisiStok($lokasi, $bahan->pluck('id')->all());

        $idTercantum = collect(old('baris', $baris))->pluck('bahan_baku_id')->filter()->map(fn ($id) => (int) $id);
        $infoBahan = $bahan->whereIn('id', $idTercantum)->mapWithKeys(fn (BahanBaku $b) => [
            $b->id => [
                'nama'   => $b->nama,
                'lain'   => (string) $b->nama_lain,
                'satuan' => $b->satuanBeli->nama,
                'info'   => $this->teksKondisi($b, $kondisi[$b->id]),
            ],
        ]);

        return view('cabang.permintaan.form', [
            'lokasi'           => $lokasi,
            'baris'            => $baris,
            'infoBahan'        => $infoBahan,
            'jumlahUsulan'     => $jumlahUsulan,
            'dataBahan'        => $bahan->map(fn (BahanBaku $b) => [
                'id'     => $b->id,
                'nama'   => $b->nama,
                'lain'   => (string) $b->nama_lain,
                'satuan' => $b->satuanBeli->nama,
                'info'   => $this->teksKondisi($b, $kondisi[$b->id]),
                'kurang' => $kondisi[$b->id]['minimum'] > 0 && $kondisi[$b->id]['saldo'] < $kondisi[$b->id]['minimum'],
            ])->values(),
            'maksAlasan'       => PermintaanRequest::MAKS_ALASAN,
        ]);
    }

    /** "Stok sekarang 1,2 kg · minimum 3 kg" dalam satuan beli. */
    private function teksKondisi(BahanBaku $bahan, array $kondisi): string
    {
        $satuan = $bahan->satuanBeli->nama;
        $teks = 'Stok sekarang '.Angka::tampil($bahan->keSatuanBeli($kondisi['saldo'])).' '.$satuan;

        if ($kondisi['minimum'] > 0) {
            $teks .= ' · minimum '.Angka::tampil($bahan->keSatuanBeli($kondisi['minimum'])).' '.$satuan;
        }

        return $teks;
    }

    private function pastikanMilikLokasi(Request $request, Permintaan $permintaan): void
    {
        abort_unless((int) $permintaan->cabang_id === $this->lokasi($request)->id, 404);
    }
}
