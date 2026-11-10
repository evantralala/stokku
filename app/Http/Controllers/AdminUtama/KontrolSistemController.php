<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\AksesRequest;
use App\Http\Requests\AdminUtama\AktifAkunRequest;
use App\Http\Requests\AdminUtama\ModulRequest;
use App\Models\User;
use App\Services\KontrolSistemService;
use App\Services\PenggunaService;
use App\Support\DaftarKontrol;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Bagian sistem di halaman Pengaturan: nyala/mati akun, fitur (modul), dan hak akses per peran. */
class KontrolSistemController extends Controller
{
    public function __construct(
        private KontrolSistemService $kontrol,
        private PenggunaService $akun,
    ) {}

    /** Alamat lama; isinya sekarang bagian dari halaman Pengaturan. */
    public function index(): RedirectResponse
    {
        return redirect()->route('admin-utama.pengaturan');
    }

    public function ubahAkun(AktifAkunRequest $request, User $pengguna): RedirectResponse
    {
        $aktif = $request->boolean('aktif');

        try {
            $this->akun->aturAktif($pengguna, $aktif, $request->user());
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.pengaturan')->with('galat', $e->getMessage());
        }

        return redirect()->route('admin-utama.pengaturan')->with('sukses', $aktif
            ? "Akun {$pengguna->nama} menyala lagi."
            : "Akun {$pengguna->nama} dimatikan dan langsung keluar dari aplikasi.");
    }

    public function ubahModul(ModulRequest $request, string $modul): RedirectResponse
    {
        $nyala = $request->boolean('nyala');

        try {
            $this->kontrol->aturModul($modul, $nyala, $request->user());
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.pengaturan')->with('galat', $e->getMessage());
        }

        $label = DaftarKontrol::MODUL[$modul]['label'];

        return redirect()->route('admin-utama.pengaturan')->with('sukses', $nyala
            ? "Modul {$label} menyala."
            : "Modul {$label} dimatikan.");
    }

    /** Form hak akses satu peran. Dibuka sebagai popup, atau halaman biasa tanpa JavaScript. */
    public function formAkses(string $peran): View
    {
        $role = DaftarKontrol::peranDariPotongan($peran);

        return view('admin-utama.kontrol-sistem.akses', [
            'peran'      => $peran,
            'labelPeran' => User::LABEL_ROLE[$role],
            'menu'       => $this->kontrol->tabelAkses($role),
            'labelAksi'  => DaftarKontrol::LABEL_AKSI,
        ]);
    }

    public function simpanAkses(AksesRequest $request, string $peran): RedirectResponse
    {
        $role = DaftarKontrol::peranDariPotongan($peran);

        try {
            $berubah = $this->kontrol->aturAkses($role, (array) $request->validated('akses', []), $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['akses' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin-utama.pengaturan')->with('sukses', $berubah > 0
            ? 'Hak akses '.User::LABEL_ROLE[$role].' disimpan.'
            : 'Tidak ada hak akses yang berubah.');
    }
}
