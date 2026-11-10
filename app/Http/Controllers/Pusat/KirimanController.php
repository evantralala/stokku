<?php

namespace App\Http\Controllers\Pusat;

use App\Exceptions\StokTidakCukup;
use App\Http\Controllers\Concerns\UnduhPeriode;
use App\Http\Requests\Pusat\KirimBanyakRequest;
use App\Http\Requests\Pusat\KirimanRequest;
use App\Models\Kiriman;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Persetujuan;
use App\Services\CetakDokumenService;
use App\Services\EksporDaftarService;
use App\Services\KirimanService;
use App\Services\StokService;
use App\Support\DokumenPdf;
use App\Support\Periode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Kiriman Gudang Pusat ke cabang. Admin Utama memakai halaman yang sama lewat AdminUtama\KirimanController. */
class KirimanController extends PusatController
{
    use UnduhPeriode;

    /** Awalan nama route halaman kiriman. */
    protected const RUTE = 'pusat.kiriman';

    public function __construct(
        private KirimanService $layanan,
        private StokService $stok,
    ) {
    }

    /** Permintaan yang siap dikirim, dan riwayat kiriman. */
    /** Excel kiriman keluar Gudang Pusat per periode, satu baris per barang. */
    public function unduh(Request $request, EksporDaftarService $ekspor): View|StreamedResponse
    {
        $lokasi = $this->lokasi($request);

        return $this->unduhPerPeriode($request, 'Kiriman dari '.$lokasi->nama, static::RUTE.'.unduh', route(static::RUTE),
            fn (Periode $periode) => $ekspor->kiriman($lokasi, $periode, masuk: false));
    }

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $siapKirim = $this->siapKirim()->load('detail.bahanBaku:id,nama');
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->pluck('saldo', 'bahan_baku_id');
        $kurang = $siapKirim->mapWithKeys(fn (Permintaan $p) => [$p->id => $p->detail
            ->filter(fn (PermintaanDetail $d) => $d->utang_kiriman > 0 && $d->utang_kiriman > (float) ($saldo[$d->bahan_baku_id] ?? 0) + 0.0005)
            ->map(fn (PermintaanDetail $d) => $d->bahanBaku->nama)
            ->values()
            ->all()]);

        $kiriman = Kiriman::query()
            ->dari($lokasi->id)
            ->with('keCabang:id,nama')
            ->withCount('detail')
            ->orderByDesc('dikirim_pada')
            ->orderByDesc('id')
            ->paginate(20);

        return view('pusat.kiriman.index', [
            'siapKirim' => $siapKirim,
            'kurang'    => $kurang,
            'kiriman'   => $kiriman,
            'lokasi'    => $lokasi,
            'rute'      => static::RUTE,
        ]);
    }

    /** PDF satu permintaan yang sudah disetujui, untuk menyiapkan barang. */
    public function pdfPermintaan(Request $request, Permintaan $permintaan, CetakDokumenService $cetak): Response
    {
        $this->lokasi($request);

        return DokumenPdf::unduh($cetak->permintaan($permintaan, $request->user()), $cetak->namaFile('permintaan', $permintaan->nomor));
    }

    /** PDF permintaan yang belum terkirim penuh, setiap permintaan memuat semua barang dan statusnya. */
    public function pdfSiapKirim(Request $request, CetakDokumenService $cetak): Response
    {
        $this->lokasi($request);

        return DokumenPdf::unduh($cetak->daftarPermintaan($this->siapKirim(), $request->user()), $cetak->namaFile('daftar permintaan siap kirim'));
    }

    public function suratJalan(Request $request, Kiriman $kiriman, CetakDokumenService $cetak): Response
    {
        abort_unless((int) $kiriman->dari_cabang_id === $this->lokasi($request)->id, 404);

        return DokumenPdf::unduh($cetak->suratJalan($kiriman, $request->user()), $cetak->namaFile('surat jalan', $kiriman->nomor));
    }

    public function create(Request $request, Permintaan $permintaan): View|RedirectResponse
    {
        $lokasi = $this->lokasi($request);

        $detail = $permintaan->detail()
            ->masihUtang()
            ->with(['bahanBaku.satuanBeli', 'bahanBaku.satuanDasar'])
            ->get();

        if ($permintaan->status !== Permintaan::STATUS_MENUNGGU || $detail->isEmpty()) {
            return redirect()->route(static::RUTE)->with('galat', 'Tidak ada barang dari permintaan ini yang perlu dikirim.');
        }

        $baris = $detail->map(function (PermintaanDetail $d) use ($lokasi) {
            $stok = $this->stok->saldo($lokasi->id, $d->bahan_baku_id);
            $kirim = round(min((float) $d->utang_kiriman, max(0, $stok)), 3);

            return [
                'detail' => $d,
                'stok'   => $stok,
                'kirim'  => $kirim,
                'kurang' => round((float) $d->utang_kiriman - $kirim, 3),
            ];
        });

        $keputusan = Persetujuan::query()
            ->where('dokumen_tipe', Permintaan::DOKUMEN_TIPE)
            ->where('dokumen_id', $permintaan->id)
            ->where('keputusan', Persetujuan::KEPUTUSAN_DISETUJUI)
            ->with('oleh:id,nama')
            ->latest('id')
            ->first();

        return view('pusat.kiriman.form', [
            'permintaan'  => $permintaan->load('cabang:id,nama'),
            'lokasi'      => $lokasi,
            'baris'       => $baris,
            'keputusan'   => $keputusan,
            'maksCatatan' => KirimanRequest::MAKS_CATATAN,
            'rute'        => static::RUTE,
        ]);
    }

    public function store(KirimanRequest $request, Permintaan $permintaan): RedirectResponse
    {
        $lokasi = $this->lokasi($request);

        try {
            $kiriman = $this->layanan->kirimYangAda($permintaan, $lokasi, $request->user(), $request->safe()->only(['catatan_pusat']));
        } catch (StokTidakCukup $e) {
            return back()->withInput()->with('galat', $e->pesanDi($lokasi->nama));
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        $sisa = $permintaan->detail()->masihUtang()->count();

        return redirect()
            ->route(static::RUTE.'.lihat', $kiriman)
            ->with('sukses', "Kiriman {$kiriman->nomor} tercatat. Stok gudang sudah dikurangi."
                .($sisa > 0 ? " {$sisa} barang belum terkirim penuh karena stok kurang; sisanya tetap di Siap Dikirim." : ''));
    }

    public function show(Request $request, Kiriman $kiriman): View
    {
        abort_unless((int) $kiriman->dari_cabang_id === $this->lokasi($request)->id, 404);

        $kiriman->load([
            'keCabang:id,nama',
            'pengirim:id,nama',
            'permintaan:id,nomor',
            'detail.bahanBaku.satuanBeli',
            'detail.pemeriksa:id,nama',
        ]);

        return view('kiriman.lihat', ['kiriman' => $kiriman, 'rute' => static::RUTE, 'judulDaftar' => 'Kiriman']);
    }

    public function simpanKirimBanyak(KirimBanyakRequest $request): RedirectResponse
    {
        $lokasi = $this->lokasi($request);
        $kembali = redirect()->route(static::RUTE);

        $daftar = Permintaan::query()
            ->where('status', Permintaan::STATUS_MENUNGGU)
            ->whereHas('detail', fn ($q) => $q->masihUtang())
            ->whereKey($request->dipilih())
            ->get()
            ->sortBy(fn (Permintaan $p) => $p->diajukan_pada->timestamp)
            ->values();

        if ($daftar->isEmpty()) {
            return $kembali->with('galat', 'Centang minimal satu permintaan yang masih perlu dikirim.');
        }

        $hasil = $this->layanan->kirimBanyak($daftar, $lokasi, $request->user());
        $gagal = collect($hasil['gagal'])->map(fn (string $p, string $n) => "{$n}: {$p}")->implode(' ');

        if ($hasil['kiriman'] === []) {
            return $kembali->with('galat', 'Tidak ada yang terkirim. '.$gagal);
        }

        $nomor = collect($hasil['kiriman'])->pluck('nomor')->implode(', ');
        $sebagian = Permintaan::query()->whereKey($daftar->modelKeys())->whereHas('detail', fn ($q) => $q->masihUtang())->count();
        $pesan = count($hasil['kiriman'])." kiriman tercatat ({$nomor}). Stok gudang sudah dikurangi; cetak surat jalan dari Riwayat."
            .($sebagian > 0 ? " {$sebagian} permintaan baru terkirim sebagian karena stok kurang; sisanya tetap di Siap Dikirim." : '');

        return $kembali->with('sukses', $gagal !== '' ? $pesan.' Belum terkirim: '.$gagal : $pesan);
    }

    /** Permintaan yang masih punya barang disetujui belum terkirim, terlama dulu. */
    private function siapKirim(): Collection
    {
        return Permintaan::query()
            ->where('status', Permintaan::STATUS_MENUNGGU)
            ->whereHas('detail', fn ($q) => $q->masihUtang())
            ->withCount(['detail as utang_count' => fn ($q) => $q->masihUtang()])
            ->with('cabang:id,nama')
            ->get()
            ->sortBy(fn (Permintaan $p) => $p->diajukan_pada->timestamp)
            ->values();
    }
}
