<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\ImporOpnameRequest;
use App\Http\Requests\OpnameHitungRequest;
use App\Models\Cabang;
use App\Models\Opname;
use App\Services\CetakTransaksiService;
use App\Services\EksporDaftarService;
use App\Services\Impor\ImporOpnameService;
use App\Support\BukuExcel;
use App\Services\OpnameService;
use App\Support\DokumenPdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman stock opname yang sama untuk Admin Pusat dan Admin Cabang.
 * Controller pemakai menyediakan lokasi(Request) dan properti $rute (awalan nama route).
 * Stok sistem (qty_sistem) tidak pernah dikirim ke tampilan petugas; templat Impor Opname sengaja memuat stok sistem.
 */
trait KelolaOpname
{
    use AlurImporExcel;

    abstract protected function lokasi(Request $request): Cabang;

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $daftar = Opname::query()
            ->diLokasi($lokasi->id)
            ->with('petugas:id,nama')
            ->withCount('detail')
            ->orderByDesc('id')
            ->paginate(20);

        return view('opname.index', [
            'daftar'  => $daftar,
            'rute'    => $this->rute,
            'lokasi'  => $lokasi,
            'berjalan' => Opname::query()->diLokasi($lokasi->id)->belumSelesai()->first(),
        ]);
    }

    public function mulai(Request $request, OpnameService $layanan): RedirectResponse
    {
        try {
            $opname = $layanan->mulai($request->user(), $this->lokasi($request));
        } catch (DomainException $e) {
            return redirect()->route($this->rute)->with('galat', $e->getMessage());
        }

        return redirect()
            ->route($this->rute.'.lihat', $opname)
            ->with('sukses', "Opname {$opname->nomor} dimulai. Stok lokasi ini dikunci sampai Admin Utama memutuskan.");
    }

    /** PDF hasil opname. Stok sistem dan selisih baru ikut dicetak setelah opname disetujui (hitung buta). */
    public function pdf(Request $request, Opname $opname, CetakTransaksiService $cetak): Response
    {
        $this->pastikanMilikLokasi($request, $opname);

        return DokumenPdf::unduh(
            $cetak->opname($opname, $request->user(), lengkap: $opname->status === Opname::STATUS_DISETUJUI),
            $cetak->namaFile('hasil stock opname', $opname->nomor)
        );
    }

    /** Excel hasil opname. Stok sistem dan selisih baru ikut setelah opname disetujui, sama seperti PDF. */
    public function excel(Request $request, Opname $opname, EksporDaftarService $ekspor): StreamedResponse
    {
        $this->pastikanMilikLokasi($request, $opname);

        return BukuExcel::unduh(
            $ekspor->hasilOpname($opname, lengkap: $opname->status === Opname::STATUS_DISETUJUI),
            BukuExcel::namaBerkas('hasil-opname', $opname->nomor)
        );
    }

    public function unggahImpor(Request $request, ImporOpnameService $impor): View
    {
        return $this->tampilUnggah($impor->diLokasi($this->lokasi($request)), $this->urlImpor());
    }

    public function templatImpor(Request $request, ImporOpnameService $impor): StreamedResponse
    {
        return $this->unduhTemplat($impor->diLokasi($this->lokasi($request)));
    }

    public function pratinjauImpor(ImporOpnameRequest $request, ImporOpnameService $impor): View|RedirectResponse
    {
        return $this->tampilPratinjau($request, $impor->diLokasi($this->lokasi($request)), $this->rute, $this->urlImpor());
    }

    public function simpanImporOpname(Request $request, ImporOpnameService $impor): RedirectResponse
    {
        return $this->simpanImpor($request, $impor->diLokasi($this->lokasi($request)), $this->rute, route($this->rute));
    }

    /** @return array{templat: string, pratinjau: string, simpan: string, kembali: string} */
    private function urlImpor(): array
    {
        return [
            'templat'   => route($this->rute.'.impor.templat'),
            'pratinjau' => route($this->rute.'.impor.pratinjau'),
            'simpan'    => route($this->rute.'.impor.simpan'),
            'kembali'   => route($this->rute),
        ];
    }

    public function show(Request $request, Opname $opname): View
    {
        $this->pastikanMilikLokasi($request, $opname);

        $opname->load(['petugas:id,nama', 'persetujuan.oleh:id,nama']);

        // Kolom stok sistem sengaja tidak diambil.
        $detail = $opname->detail()
            ->select(['id', 'opname_id', 'bahan_baku_id', 'qty_fisik', 'catatan'])
            ->with(['bahanBaku:id,nama,foto,kategori_id,satuan_beli_id,isi_per_satuan_beli', 'bahanBaku.satuanBeli:id,nama', 'bahanBaku.kategori:id,nama'])
            ->get()
            ->sortBy(fn ($d) => [$d->bahanBaku->kategori->nama, $d->bahanBaku->nama])
            ->values();

        return view('opname.lihat', [
            'opname'      => $opname,
            'detail'      => $detail,
            'rute'        => $this->rute,
            'maksCatatan' => OpnameHitungRequest::MAKS_CATATAN,
        ]);
    }

    public function simpan(OpnameHitungRequest $request, OpnameService $layanan, Opname $opname): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $opname);

        try {
            $belum = $layanan->simpanHitungan($opname, $request->validated('hitung'), $request->mauKirim());
        } catch (DomainException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        $pesan = match (true) {
            $request->mauKirim() => "Opname {$opname->nomor} dikirim ke Admin Utama. Stok tetap dikunci sampai Admin Utama memutuskan.",
            $belum > 0           => "Hasil hitung tersimpan. Masih {$belum} bahan yang belum dihitung.",
            default              => 'Semua bahan sudah dihitung. Periksa sekali lagi, lalu kirim ke Admin Utama.',
        };

        return redirect()->route($this->rute.'.lihat', $opname)->with('sukses', $pesan);
    }

    public function batalkan(Request $request, OpnameService $layanan, Opname $opname): RedirectResponse
    {
        $this->pastikanMilikLokasi($request, $opname);

        try {
            $layanan->batalkan($opname);
        } catch (DomainException $e) {
            return redirect()->route($this->rute.'.lihat', $opname)->with('galat', $e->getMessage());
        }

        return redirect()->route($this->rute)->with('sukses', "Opname {$opname->nomor} dibatalkan. Stok lokasi tidak lagi dikunci.");
    }

    private function pastikanMilikLokasi(Request $request, Opname $opname): void
    {
        abort_unless((int) $opname->cabang_id === $this->lokasi($request)->id, 404);
    }
}
