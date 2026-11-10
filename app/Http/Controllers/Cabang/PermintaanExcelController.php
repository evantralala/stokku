<?php

namespace App\Http\Controllers\Cabang;

use App\Http\Controllers\Concerns\AlurImporExcel;
use App\Http\Requests\Cabang\ImporPermintaanRequest;
use App\Models\Permintaan;
use App\Services\EksporDaftarService;
use App\Services\Impor\ImporPermintaanService;
use App\Support\BukuExcel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Impor permintaan lewat Excel oleh Admin Cabang (unggah, templat, pratinjau, simpan) dan Excel satu permintaan. */
class PermintaanExcelController extends CabangController
{
    use AlurImporExcel;

    public function __construct(private ImporPermintaanService $impor)
    {
    }

    public function unggah(Request $request): View
    {
        return $this->tampilUnggah($this->layanan($request), $this->url());
    }

    public function templat(Request $request): StreamedResponse
    {
        return $this->unduhTemplat($this->layanan($request));
    }

    public function pratinjau(ImporPermintaanRequest $request): View|RedirectResponse
    {
        return $this->tampilPratinjau($request, $this->layanan($request), $this->kunciSesi($request), $this->url());
    }

    public function simpan(Request $request): RedirectResponse
    {
        return $this->simpanImpor($request, $this->layanan($request), $this->kunciSesi($request), $this->url()['kembali']);
    }

    /** Excel satu permintaan; kolom Bahan dan Diminta bisa diunggah lagi untuk meminta ulang. */
    public function excel(Request $request, Permintaan $permintaan, EksporDaftarService $ekspor): StreamedResponse
    {
        abort_unless((int) $permintaan->cabang_id === $this->lokasi($request)->id, 404);

        return BukuExcel::unduh($ekspor->satuPermintaan($permintaan), BukuExcel::namaBerkas('permintaan', $permintaan->nomor));
    }

    private function layanan(Request $request): ImporPermintaanService
    {
        return $this->impor->diLokasi($this->lokasi($request));
    }

    private function kunciSesi(Request $request): string
    {
        return 'permintaan.'.$this->lokasi($request)->id;
    }

    /** @return array{templat: string, pratinjau: string, simpan: string, kembali: string} */
    private function url(): array
    {
        return [
            'templat'   => route('cabang.permintaan.impor.templat'),
            'pratinjau' => route('cabang.permintaan.impor.pratinjau'),
            'simpan'    => route('cabang.permintaan.impor.simpan'),
            'kembali'   => route('cabang.permintaan'),
        ];
    }
}
