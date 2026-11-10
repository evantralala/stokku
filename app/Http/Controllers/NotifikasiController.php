<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Notifikasi milik pengguna yang sedang masuk (semua peran). */
class NotifikasiController extends Controller
{
    public function index(Request $request): View
    {
        $daftar = Notifikasi::query()
            ->milik($request->user()->id)
            ->latest('id')
            ->paginate(20);

        return view('notifikasi.index', [
            'daftar'      => $daftar,
            'belumDibaca' => Notifikasi::query()->milik($request->user()->id)->belumDibaca()->count(),
        ]);
    }

    /** Tandai dibaca lalu buka halaman tujuannya. */
    public function buka(Request $request, Notifikasi $notifikasi): RedirectResponse
    {
        abort_unless((int) $notifikasi->user_id === $request->user()->id, 404);

        $notifikasi->tandaiDibaca();

        return $notifikasi->tautan
            ? redirect()->to($notifikasi->tautan)
            : redirect()->route('notifikasi');
    }

    public function bacaSemua(Request $request): RedirectResponse
    {
        $jumlah = Notifikasi::query()
            ->milik($request->user()->id)
            ->belumDibaca()
            ->update(['dibaca_pada' => now()]);

        return redirect()->route('notifikasi')->with('sukses', $jumlah > 0
            ? "{$jumlah} notifikasi ditandai sudah dibaca."
            : 'Semua notifikasi sudah dibaca.');
    }
}
