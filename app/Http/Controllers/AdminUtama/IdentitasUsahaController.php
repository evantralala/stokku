<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\IdentitasUsahaRequest;
use App\Services\IdentitasUsahaService;
use DomainException;
use Illuminate\Http\RedirectResponse;

/** Simpan identitas usaha dari Pengaturan > Identitas Usaha. */
class IdentitasUsahaController extends Controller
{
    public function __invoke(IdentitasUsahaRequest $request, IdentitasUsahaService $identitas): RedirectResponse
    {
        $kembali = redirect()->to(route('admin-utama.pengaturan').'#identitas');

        try {
            $berubah = $identitas->simpan(
                $request->safe()->only(array_keys(IdentitasUsahaService::BAWAAN)),
                $request->file('logo'),
                $request->boolean('logo_bawaan'),
                $request->user(),
            );
        } catch (DomainException $e) {
            return $kembali->withInput()->withErrors(['logo' => $e->getMessage()]);
        }

        return $kembali->with('sukses', $berubah > 0 ? 'Identitas usaha disimpan.' : 'Tidak ada yang berubah.');
    }
}
