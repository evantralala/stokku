<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\PerbaruiStokExcelRequest;
use App\Models\Cabang;
use App\Services\Impor\ImporPenyesuaianStokService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pembaruan jumlah stok lewat Excel di halaman Stok: unggah (popup), unduh file stok, cek, simpan.
 * Dipakai bersama PantauStok (lokasiPantauan, $rute, bolehPilihLokasi).
 */
trait PerbaruiStokExcel
{
    use AlurImporExcel;

    public function excel(Request $request, ImporPenyesuaianStokService $impor): View
    {
        $lokasi = $this->lokasiPantauan($request);

        return $this->tampilUnggah($impor->diLokasi($lokasi), $this->urlExcel($lokasi));
    }

    public function unduhExcel(Request $request, ImporPenyesuaianStokService $impor): StreamedResponse
    {
        return $this->unduhTemplat($impor->diLokasi($this->lokasiPantauan($request)));
    }

    public function pratinjauExcel(PerbaruiStokExcelRequest $request, ImporPenyesuaianStokService $impor): View|RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);

        return $this->tampilPratinjau($request, $impor->diLokasi($lokasi), $this->kunciSesiExcel($lokasi), $this->urlExcel($lokasi));
    }

    public function simpanExcel(Request $request, ImporPenyesuaianStokService $impor): RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);

        return $this->simpanImpor($request, $impor->diLokasi($lokasi), $this->kunciSesiExcel($lokasi), $this->urlExcel($lokasi)['kembali']);
    }

    private function kunciSesiExcel(Cabang $lokasi): string
    {
        return 'stok.'.$lokasi->id;
    }

    /** @return array{templat: string, pratinjau: string, simpan: string, kembali: string} */
    private function urlExcel(Cabang $lokasi): array
    {
        $parameter = $this->bolehPilihLokasi() ? ['lokasi' => $lokasi->id] : [];

        return [
            'templat'   => route($this->rute.'.excel.unduh', $parameter),
            'pratinjau' => route($this->rute.'.excel.pratinjau', $parameter),
            'simpan'    => route($this->rute.'.excel.simpan', $parameter),
            'kembali'   => route($this->rute, $parameter),
        ];
    }
}
