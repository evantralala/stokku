<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\PengaturanRequest;
use App\Models\Pengaturan;
use App\Models\User;
use App\Services\IdentitasUsahaService;
use App\Http\Requests\AdminUtama\IdentitasUsahaRequest;
use App\Services\KontrolSistemService;
use App\Services\PengaturanService;
use App\Services\PeriodeBukuService;
use App\Support\DaftarKontrol;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pengaturan: angka aturan umum, identitas usaha, fitur nyala/mati, akun, hak akses, dan periode buku.
 * Daftar isian umum mengikuti isi tabel pengaturan, bukan ditulis di kode. Aksi bagian sistem ada di KontrolSistemController.
 */
class PengaturanController extends Controller
{
    public function index(KontrolSistemService $kontrol, PeriodeBukuService $periode, IdentitasUsahaService $identitas): View
    {
        return view('admin-utama.pengaturan.index', [
            'daftar'         => Pengaturan::query()->orderBy('urutan')->orderBy('kunci')->get(),
            'akunPeran'      => User::query()->tampil()->with('cabang:id,nama')->orderBy('nama')->get()->groupBy('role'),
            'labelPeran'     => User::LABEL_ROLE,
            'modul'          => $kontrol->daftarModul(),
            'akses'          => collect(DaftarKontrol::PERAN)->map(fn (string $role) => [
                'label' => User::LABEL_ROLE[$role],
                'menu'  => $kontrol->tabelAkses($role),
            ])->all(),
            'periodeMulai'   => $periode->mulaiBerjalan(),
            'periodeArsip'   => $periode->daftar(),
            'periodeSiap'    => $periode->siap(),
            'identitas'      => $identitas->data(),
            'logoUnggahan'   => $identitas->adaLogoUnggahan(),
            'maksIdentitas'  => ['nama' => IdentitasUsahaRequest::MAKS_NAMA, 'singkat' => IdentitasUsahaRequest::MAKS_SINGKAT, 'kota' => IdentitasUsahaRequest::MAKS_KOTA],
        ]);
    }

    public function simpan(PengaturanRequest $request, PengaturanService $layanan): RedirectResponse
    {
        $berubah = $layanan->simpan($request->daftarPengaturan(), $request->validated('nilai', []), $request->user());

        $pesan = $berubah > 0
            ? "{$berubah} pengaturan berhasil diperbarui."
            : 'Tidak ada pengaturan yang berubah.';

        return redirect()->route('admin-utama.pengaturan')->with('sukses', $pesan);
    }
}
