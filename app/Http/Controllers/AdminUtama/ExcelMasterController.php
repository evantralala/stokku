<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Concerns\AlurImporExcel;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Services\Impor\DaftarExcelMaster;
use App\Services\Impor\ImporExcel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Impor dan ekspor Excel milik Admin Utama (data master, resep, penjualan, stok minimum). */
class ExcelMasterController extends Controller
{
    use AlurImporExcel;

    public function unggah(string $master): View
    {
        return $this->tampilUnggah($this->layananImpor($master), $this->url($master));
    }

    public function templat(string $master): StreamedResponse
    {
        return $this->unduhTemplat($this->layananImpor($master));
    }

    public function ekspor(string $master): StreamedResponse
    {
        return $this->unduhEkspor(DaftarExcelMaster::layanan($master));
    }

    public function pratinjau(ImporDataRequest $request, string $master): View|RedirectResponse
    {
        return $this->tampilPratinjau($request, $this->layananImpor($master), $master, $this->url($master));
    }

    public function simpan(Request $request, string $master): RedirectResponse
    {
        return $this->simpanImpor($request, $this->layananImpor($master), $master, $this->url($master)['kembali']);
    }

    private function layananImpor(string $master): ImporExcel
    {
        $layanan = DaftarExcelMaster::layanan($master);

        abort_unless($layanan->bisaImpor(), 404);

        return $layanan;
    }

    /** @return array{templat: string, pratinjau: string, simpan: string, kembali: string} */
    private function url(string $master): array
    {
        return [
            'templat'   => route('admin-utama.excel.templat', $master),
            'pratinjau' => route('admin-utama.excel.pratinjau', $master),
            'simpan'    => route('admin-utama.excel.simpan', $master),
            'kembali'   => route(DaftarExcelMaster::RUTE_KEMBALI[$master] ?? 'admin-utama.'.$master),
        ];
    }
}
