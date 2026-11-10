<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Services\RiwayatService;
use App\Support\DaftarRiwayat;
use App\Support\Teks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Riwayat perubahan per fitur (popup dari tombol Riwayat), ?jenis= dan opsional ?objek= untuk satu data. */
class RiwayatController extends Controller
{
    public function __invoke(Request $request, RiwayatService $riwayat): View|RedirectResponse
    {
        $jenis = (string) $request->query('jenis');

        if ($jenis === '') {
            return redirect()->route($request->user()->ruteDashboard());
        }

        abort_unless(array_key_exists($jenis, DaftarRiwayat::JENIS), 404);

        $aturan = DaftarRiwayat::JENIS[$jenis];
        $objek = isset($aturan['model']) && $request->filled('objek')
            ? $aturan['model']::query()->find((int) $request->query('objek'))
            : null;

        return view('admin-utama.riwayat.index', [
            'judul'  => Teks::judul('Riwayat '.$aturan['judul']).($objek ? ': '.$objek->nama : ''),
            'daftar' => $riwayat->daftar($jenis, $objek?->getKey()),
            'kembali' => route($aturan['rute']),
        ]);
    }
}
