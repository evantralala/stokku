<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\TutupPeriodeRequest;
use App\Models\PeriodeBuku;
use App\Services\PeriodeBukuService;
use App\Support\BukuExcel;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Periode Buku di Pengaturan: tutup buku (popup, atau halaman biasa tanpa JavaScript) dan unduh arsip periode lama. */
class PeriodeBukuController extends Controller
{
    public function __construct(private PeriodeBukuService $periode) {}

    public function form(): View|RedirectResponse
    {
        if (! $this->periode->siap()) {
            return $this->belumTerpasang();
        }

        return view('admin-utama.periode.form', [
            'penghalang' => $this->periode->penghalang(),
            'mulai'      => $this->periode->mulaiBerjalan(),
            'usulNama'   => $this->periode->usulNama(),
            'kata'       => PeriodeBukuService::KATA_KONFIRMASI,
            'maksNama'   => TutupPeriodeRequest::MAKS_NAMA,
        ]);
    }

    public function simpan(TutupPeriodeRequest $request): RedirectResponse
    {
        if (! $this->periode->siap()) {
            return $this->belumTerpasang();
        }

        try {
            $periode = $this->periode->tutup($request->validated('nama'), $request->user());
        } catch (DomainException $e) {
            return back()->withInput($request->only('nama'))->with('galat', $e->getMessage());
        }

        return redirect()->to(route('admin-utama.pengaturan').'#periode')->with('sukses',
            'Periode '.$periode->nama.' ditutup. Arsip Excel dan cadangan bisa diunduh di Periode Buku; stok terakhir menjadi stok awal periode baru.');
    }

    public function unduh(Request $request, PeriodeBuku $periode, string $jenis): BinaryFileResponse
    {
        $jalur = $this->periode->jalur($periode, $jenis);
        abort_if($jalur === null, 404);

        $this->periode->catatUnduh($request->user(), $periode, $jenis);

        return response()->download($jalur, basename($jalur), [
            'Content-Type' => $jenis === 'excel' ? BukuExcel::MIME : 'application/gzip',
        ]);
    }

    private function belumTerpasang(): RedirectResponse
    {
        return redirect()->to(route('admin-utama.pengaturan').'#periode')->with('galat', PeriodeBukuService::PESAN_BELUM_TERPASANG);
    }
}
