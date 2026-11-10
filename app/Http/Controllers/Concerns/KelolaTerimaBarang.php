<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Cabang\TerimaBanyakRequest;
use App\Http\Requests\Cabang\TerimaKirimanRequest;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Services\EksporDaftarService;
use App\Services\KirimanService;
use App\Support\Periode;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penerimaan kiriman di lokasi tujuan (cabang, atau Gudang Pusat untuk retur dari cabang): barang yang tiba
 * dicocokkan dengan data kiriman, satu per satu atau beberapa sekaligus (centang, "sesuai semua"). Surat jalan dicetak oleh pengirim dan dibawa bersama barang, jadi tidak diunduh di sini.
 * Controller pemakai menyediakan lokasi(Request) dan properti $rute (mis. 'cabang.terima-barang').
 */
trait KelolaTerimaBarang
{
    use UnduhPeriode;

    /** Excel kiriman yang diterima lokasi ini per periode, satu baris per barang. */
    public function unduh(Request $request, EksporDaftarService $ekspor): View|StreamedResponse
    {
        $lokasi = $this->lokasi($request);

        return $this->unduhPerPeriode($request, 'Kiriman ke '.$lokasi->nama, $this->rute.'.unduh', route($this->rute),
            fn (Periode $periode) => $ekspor->kiriman($lokasi, $periode, masuk: true));
    }

    public function index(Request $request): View
    {
        $lokasi = $this->lokasi($request);

        $daftar = Kiriman::query()
            ->ke($lokasi->id)
            ->where('status', '!=', Kiriman::STATUS_DISIAPKAN)
            ->with('dariCabang:id,nama')
            ->withCount('detail')
            ->orderByRaw('status = ? desc', [Kiriman::STATUS_DIKIRIM])
            ->orderByDesc('dikirim_pada')
            ->paginate(20);

        return view('terima-barang.index', ['daftar' => $daftar, 'rute' => $this->rute, 'lokasi' => $lokasi]);
    }

    public function show(Request $request, Kiriman $kiriman): View
    {
        $this->pastikanTujuan($request, $kiriman);

        $kiriman->load('dariCabang:id,nama', 'keCabang:id,nama', 'pengirim:id,nama', 'permintaan:id,nomor');

        $detail = KirimanDetail::query()
            ->where('kiriman_id', $kiriman->id)
            ->with('bahanBaku.satuanBeli')
            ->orderBy('id')
            ->get();

        return view('terima-barang.lihat', [
            'kiriman'        => $kiriman,
            'detail'         => $detail,
            'rute'           => $this->rute,
            'pilihanKondisi' => KirimanDetail::LABEL_KONDISI,
            'pilihanSesuai'  => TerimaKirimanRequest::LABEL_KESESUAIAN,
            'maksCatatan'    => TerimaKirimanRequest::MAKS_CATATAN,
        ]);
    }

    public function terima(TerimaKirimanRequest $request, Kiriman $kiriman, KirimanService $layanan): RedirectResponse
    {
        $lokasi = $this->lokasi($request);
        $this->pastikanTujuan($request, $kiriman);

        try {
            $sesuai = $request->sesuai()
                ? $layanan->terimaSesuai($kiriman, $request->user())
                : $layanan->terima($kiriman, $request->user(), $request->validated('terima'));
        } catch (DomainException $e) {
            return redirect()->route($this->rute.'.lihat', $kiriman)->withInput()->with('galat', $e->getMessage());
        }

        $pesan = $sesuai
            ? "Kiriman {$kiriman->nomor} diterima sesuai. Stok {$lokasi->nama} sudah bertambah."
            : "Kiriman {$kiriman->nomor} diterima. Ada selisih dengan jumlah kiriman; Admin Utama akan diberi tahu.";

        return redirect()->route($this->rute.'.lihat', $kiriman)->with('sukses', $pesan);
    }

    public function simpanTerimaBanyak(TerimaBanyakRequest $request, KirimanService $layanan): RedirectResponse
    {
        $lokasi = $this->lokasi($request);
        $kembali = redirect()->route($this->rute);

        $daftar = Kiriman::query()
            ->ke($lokasi->id)
            ->where('status', Kiriman::STATUS_DIKIRIM)
            ->whereKey($request->dipilih())
            ->orderBy('dikirim_pada')
            ->get();

        if ($daftar->isEmpty()) {
            return $kembali->with('galat', 'Centang minimal satu kiriman yang belum diterima.');
        }

        $hasil = $layanan->terimaSesuaiBanyak($daftar, $request->user());

        if ($hasil['diterima'] === []) {
            return $kembali->with('galat', 'Tidak ada kiriman yang diterima. '.collect($hasil['gagal'])->map(fn ($p, $n) => "{$n}: {$p}")->implode(' '));
        }

        $pesan = count($hasil['diterima']).' kiriman diterima sesuai ('.implode(', ', $hasil['diterima']).'). Stok '.$lokasi->nama.' sudah bertambah.';

        if ($hasil['gagal'] !== []) {
            $pesan .= ' Gagal: '.collect($hasil['gagal'])->map(fn ($p, $n) => "{$n} ({$p})")->implode(', ').'.';
        }

        return $kembali->with('sukses', $pesan);
    }

    private function pastikanTujuan(Request $request, Kiriman $kiriman): void
    {
        abort_unless((int) $kiriman->ke_cabang_id === $this->lokasi($request)->id, 404);
    }
}
