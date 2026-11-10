<?php

namespace App\Http\Controllers\Concerns;

use App\Support\BukuExcel;
use App\Support\Periode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Unduh Excel daftar per periode: tanpa ?dari tampil form pilih periode (popup), dengan ?dari langsung berkas. */
trait UnduhPeriode
{
    /**
     * @param  array<string, scalar>  $parameter  query tetap, mis. ['lokasi' => 3]
     * @param  Closure(Periode): Spreadsheet  $buat
     */
    protected function unduhPerPeriode(
        Request $request,
        string $judul,
        string $rute,
        string $kembali,
        Closure $buat,
        array $parameter = [],
    ): View|StreamedResponse {
        $periode = Periode::dariRequest($request);

        if (! $request->filled('dari')) {
            return view('unduh.periode', compact('judul', 'rute', 'kembali', 'parameter', 'periode'));
        }

        return BukuExcel::unduh($buat($periode), BukuExcel::namaBerkas($judul, $periode->dariTanggal(), $periode->sampaiTanggal()));
    }
}
