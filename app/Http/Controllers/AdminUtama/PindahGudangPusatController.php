<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\LokasiRequest;
use App\Http\Requests\AdminUtama\PindahGudangPusatRequest;
use App\Models\Cabang;
use App\Services\PindahGudangPusatService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Pindah Gudang Pusat ke lokasi baru, dari halaman Lokasi (popup, atau halaman biasa tanpa JavaScript). */
class PindahGudangPusatController extends Controller
{
    public function __construct(private PindahGudangPusatService $pindah) {}

    public function form(): View|RedirectResponse
    {
        $pusat = Cabang::pusatAktifSelain(null);

        if ($pusat === null) {
            return redirect()->route('admin-utama.lokasi')->with('galat', 'Belum ada Gudang Pusat aktif yang bisa dipindah.');
        }

        return view('admin-utama.lokasi.pindah-pusat', [
            'pusat'        => $pusat,
            'ringkasan'    => $this->pindah->ringkasan($pusat),
            'maks'         => LokasiRequest::MAKS,
        ]);
    }

    public function simpan(PindahGudangPusatRequest $request): RedirectResponse
    {
        try {
            $hasil = $this->pindah->pindahkan($request->user(), $request->safe()->except('password'));
        } catch (DomainException $e) {
            return back()->withInput($request->except('password'))->with('galat', $e->getMessage());
        }

        $pesan = "Gudang Pusat sekarang {$hasil['pusat']->nama}. {$hasil['bahan']} bahan dipindah"
            .($hasil['kiriman'] ? " lewat kiriman {$hasil['kiriman']->nomor}" : '')
            .", {$hasil['admin']} Admin Pusat ikut pindah.";

        if ($hasil['minus'] > 0) {
            $pesan .= " {$hasil['minus']} bahan yang stoknya minus tidak ikut dipindah.";
        }

        return redirect()->route('admin-utama.lokasi')->with('sukses', $pesan);
    }
}
