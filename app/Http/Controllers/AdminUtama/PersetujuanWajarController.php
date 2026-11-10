<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\SetujuiWajarRequest;
use App\Services\PersetujuanMassalService;
use Illuminate\Http\RedirectResponse;

/** Setujui sekaligus dokumen wajar yang dicentang di antrean (Persetujuan atau dashboard). */
class PersetujuanWajarController extends Controller
{
    public function setujui(SetujuiWajarRequest $request, PersetujuanMassalService $massal): RedirectResponse
    {
        $hasil = $massal->setujuiYangWajar($request->validated('dokumen'), $request->user());
        $jumlah = count($hasil['disetujui']);

        $pesan = $jumlah > 0 ? "{$jumlah} dokumen disetujui." : 'Tidak ada dokumen yang disetujui.';
        if ($hasil['dilewati'] !== []) {
            $pesan .= ' Perlu dicek: '.collect($hasil['dilewati'])->map(fn ($d) => $d['nomor'].' ('.$d['alasan'].')')->implode(', ').'.';
        }

        return back(fallback: route('admin-utama.persetujuan'))
            ->with($jumlah > 0 ? 'sukses' : 'galat', $pesan);
    }
}
