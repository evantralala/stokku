<?php

namespace App\Http\Controllers;

use App\Services\BantuanService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman Bantuan sesuai peran, modul yang menyala, dan hak akses pengguna. ?topik= membuka satu topik, ?cari= menyaring. */
class BantuanController extends Controller
{
    public function __invoke(Request $request, BantuanService $bantuan): View
    {
        $topik = $bantuan->untuk($request->user());
        $dipilih = (string) $request->query('topik');
        $cari = trim((string) $request->query('cari'));

        if ($cari !== '') {
            $kata = mb_strtolower($cari);
            $topik = array_filter($topik, fn (array $t) => str_contains(
                mb_strtolower(implode(' ', [$t['judul'], $t['ringkas'], ...$t['langkah'], ...$t['tips']])),
                $kata
            ));
        }

        return view('bantuan.index', [
            'topik'   => $topik,
            'dipilih' => array_key_exists($dipilih, $topik) ? $dipilih : null,
            'cari'    => $cari,
        ]);
    }
}
