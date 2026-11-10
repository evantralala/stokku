<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Pusat\KirimanController as KirimanPusatController;
use App\Models\Cabang;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/** Admin Utama mengirim barang langsung dari Gudang Pusat aktif, dengan halaman yang sama seperti Admin Pusat. */
class KirimanController extends KirimanPusatController
{
    protected const RUTE = 'admin-utama.kiriman';

    protected function lokasi(Request $request): Cabang
    {
        $pusat = Cabang::pusatAktifSelain(null);

        if ($pusat === null) {
            throw new HttpResponseException(redirect()->route('admin-utama.lokasi')
                ->with('galat', 'Belum ada Gudang Pusat aktif. Tambahkan lokasi berjenis Gudang Pusat dulu, lalu buka Kiriman lagi.'));
        }

        return $pusat;
    }
}
