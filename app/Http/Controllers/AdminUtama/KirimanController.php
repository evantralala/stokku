<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Pusat\KirimanController as KirimanPusatController;
use App\Models\Cabang;
use App\Models\Permintaan;
use App\Services\KirimanService;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
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

    /** Tutup sisa permintaan yang belum terkirim penuh, sehingga hilang dari Siap Dikirim. */
    public function tutupSisa(Request $request, Permintaan $permintaan, KirimanService $layanan): RedirectResponse
    {
        $kembali = redirect()->route(static::RUTE);

        try {
            $layanan->tutupSisa($permintaan, $request->user());
        } catch (DomainException $e) {
            return $kembali->with('galat', $e->getMessage());
        }

        return $kembali->with('sukses', "Sisa permintaan {$permintaan->nomor} ditutup. Permintaan dianggap selesai.");
    }
}
