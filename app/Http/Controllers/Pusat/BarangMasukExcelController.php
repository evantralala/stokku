<?php

namespace App\Http\Controllers\Pusat;

use App\Http\Controllers\Concerns\AlurImporExcel;
use App\Http\Requests\Pusat\ImporBarangMasukRequest;
use App\Services\Impor\ImporBarangMasukService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Impor dan ekspor Excel barang masuk Gudang Pusat. Hasil impor menunggu persetujuan Admin Utama. */
class BarangMasukExcelController extends PusatController
{
    use AlurImporExcel;

    private const KUNCI_SESI = 'barang-masuk';

    public function __construct(private ImporBarangMasukService $impor)
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

    public function ekspor(Request $request): StreamedResponse
    {
        return $this->unduhEkspor($this->layanan($request));
    }

    public function pratinjau(ImporBarangMasukRequest $request): View|RedirectResponse
    {
        return $this->tampilPratinjau($request, $this->layanan($request), self::KUNCI_SESI, $this->url());
    }

    public function simpan(Request $request): RedirectResponse
    {
        return $this->simpanImpor($request, $this->layanan($request), self::KUNCI_SESI, $this->url()['kembali']);
    }

    private function layanan(Request $request): ImporBarangMasukService
    {
        return $this->impor->diLokasi($this->lokasi($request));
    }

    /** @return array{templat: string, pratinjau: string, simpan: string, kembali: string} */
    private function url(): array
    {
        return [
            'templat'   => route('pusat.barang-masuk.impor.templat'),
            'pratinjau' => route('pusat.barang-masuk.impor.pratinjau'),
            'simpan'    => route('pusat.barang-masuk.impor.simpan'),
            'kembali'   => route('pusat.barang-masuk'),
        ];
    }
}
