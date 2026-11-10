<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\AlurImporExcel;
use App\Http\Requests\Cabang\ImporPenjualanRequest;
use App\Models\PenjualanHarian;
use App\Services\Impor\ImporPenjualanCabangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Impor penjualan harian lewat Excel oleh Admin Cabang: unggah (popup), templat, pratinjau, simpan. */
class PenjualanExcelController extends CabangController
{
    use AlurImporExcel;

    public function __construct(private ImporPenjualanCabangService $impor)
    {
    }

    /** ?penjualan= untuk mengubah catatan yang sudah ada: templat berisi tanggal dan porsi catatan itu. */
    public function unggah(Request $request): View
    {
        $url = $this->url();
        $catatan = $this->catatan($request);

        if ($catatan) {
            $url['templat'] = route('cabang.penjualan.impor.templat', ['penjualan' => $catatan->id]);
        }

        return $this->tampilUnggah($this->layanan($request)->untukCatatan($catatan), $url);
    }

    public function templat(Request $request): StreamedResponse
    {
        return $this->unduhTemplat($this->layanan($request)->untukCatatan($this->catatan($request)));
    }

    public function pratinjau(ImporPenjualanRequest $request): View|RedirectResponse
    {
        return $this->tampilPratinjau($request, $this->layanan($request), $this->kunciSesi($request), $this->url());
    }

    public function simpan(Request $request): RedirectResponse
    {
        return $this->simpanImpor($request, $this->layanan($request), $this->kunciSesi($request), $this->url()['kembali']);
    }

    private function layanan(Request $request): ImporPenjualanCabangService
    {
        return $this->impor->diLokasi($this->lokasi($request));
    }

    /** Catatan penjualan cabang ini dari ?penjualan=, bila ada. */
    private function catatan(Request $request): ?PenjualanHarian
    {
        $id = (int) $request->query('penjualan');

        return $id ? PenjualanHarian::query()->diLokasi($this->lokasi($request)->id)->find($id) : null;
    }

    private function kunciSesi(Request $request): string
    {
        return 'penjualan-cabang.'.$this->lokasi($request)->id;
    }

    /** @return array{templat: string, pratinjau: string, simpan: string, kembali: string} */
    private function url(): array
    {
        return [
            'templat'   => route('cabang.penjualan.impor.templat'),
            'pratinjau' => route('cabang.penjualan.impor.pratinjau'),
            'simpan'    => route('cabang.penjualan.impor.simpan'),
            'kembali'   => route('cabang.penjualan'),
        ];
    }
}
