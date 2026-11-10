<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\ImporSekaligusRequest;
use App\Observers\CatatPerubahanData;
use App\Services\Impor\ImporExcel;
use App\Services\Impor\ImporSekaligusService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor Sekaligus: satu file berlembar banyak (Lokasi, Bahan, Menu, Resep, Stok Minimum, Stok). Alurnya sama dengan
 * impor per menu: unggah (popup), cek semua lembar, lalu simpan. Jawaban "Maksudnya …?" berlaku untuk semua lembar.
 */
class ImporSekaligusController extends Controller
{
    private const SESI = 'impor_sekaligus';

    public function __construct(private ImporSekaligusService $layanan)
    {
    }

    public function unggah(): View
    {
        return view('admin-utama.impor-sekaligus.unggah', [
            'kolom'       => $this->layanan->kolom(),
            'pilihanMode' => $this->layanan->pilihanMode(),
            'mode'        => $this->layanan->mode(),
        ]);
    }

    public function templat(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => (new Xlsx($this->layanan->templat()))->save('php://output'),
            'templat-impor-sekaligus.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function pratinjau(ImporSekaligusRequest $request): View|RedirectResponse
    {
        $file = $request->file('file');
        $this->layanan->pakaiMode($request->input('mode'));

        try {
            $isi = $this->layanan->baca($file);
            $cek = CatatPerubahanData::diam(fn () => $this->layanan->periksa($isi['lembar'], [], $request->user()));
        } catch (DomainException $e) {
            return redirect()->route($request->user()->ruteDashboard())->with('galat', $e->getMessage());
        }

        $kunci = Str::random(20);
        $request->session()->put(self::SESI, [
            'kunci'     => $kunci,
            'lembar'    => $isi['lembar'],
            'nama_file' => $file->getClientOriginalName(),
            'mode'      => $this->layanan->mode(),
        ]);

        $status = [ImporExcel::STATUS_SALAH, ImporExcel::STATUS_CEK, ImporExcel::STATUS_SIAP, ImporExcel::STATUS_DILEWATI];
        $lembar = collect($cek)->map(function (array $l) use ($status) {
            $semua = collect($l['baris']);

            return $l + [
                'urut'       => $semua->sortBy(fn ($r) => [array_search($r['status'], $status, true), $r['betul'] === [] ? 1 : 0, $r['baris']])->values(),
                'hitung'     => $semua->countBy('status')->all() + array_fill_keys($status, 0),
                'dibetulkan' => $semua->where('status', ImporExcel::STATUS_SIAP)->filter(fn ($r) => $r['betul'] !== [])->count(),
            ];
        });

        return view('admin-utama.impor-sekaligus.pratinjau', [
            'lembar'    => $lembar,
            'tebakan'   => $this->daftarTebakan($lembar),
            'kunci'     => $kunci,
            'namaFile'  => $file->getClientOriginalName(),
            'diabaikan' => $isi['diabaikan'],
            'labelMode' => isset($cek['stok']) ? $this->layanan->pilihanMode()[$this->layanan->mode()][0] : null,
            'kembali'   => route($request->user()->ruteDashboard()),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $sesi = $request->session()->pull(self::SESI);
        $tujuan = redirect()->route($request->user()->ruteDashboard());

        if (! $sesi || ! hash_equals($sesi['kunci'], (string) $request->input('kunci'))) {
            return $tujuan->with('galat', 'Pratinjau sudah tidak berlaku. Unggah ulang filenya.');
        }

        $keputusan = array_filter((array) $request->input('tebakan', []), fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);

        try {
            $hasil = CatatPerubahanData::diam(fn () => $this->layanan->pakaiMode($sesi['mode'])
                ->simpan($sesi['lembar'], $keputusan, $request->user(), $sesi['nama_file']));
        } catch (DomainException $e) {
            return $tujuan->with('galat', $e->getMessage());
        }

        $galat = collect($hasil)->filter(fn (array $h) => $h['galat'] !== null)->map(fn (array $h) => $h['label'].': '.$h['galat']);

        if ($galat->isNotEmpty()) {
            return $tujuan->with('galat', $this->layanan->pesanSimpan($hasil).' Tidak tersimpan: '.$galat->implode(' '));
        }

        return $tujuan->with('sukses', $this->layanan->pesanSimpan($hasil));
    }

    /**
     * Pertanyaan "Maksudnya …?" unik dari semua lembar, dengan tempat munculnya (mis. Resep baris 3).
     *
     * @return list<array{kunci: string, label: string, asli: string, saran: string, ada: bool, baris: list<string>}>
     */
    private function daftarTebakan(Collection $lembar): array
    {
        return $lembar->flatMap(fn (array $l) => collect($l['baris'])->flatMap(fn (array $r) => collect($r['tebakan'])
                ->map(fn (array $t) => $t + ['tempat' => $l['label'].' '.$r['baris']])))
            ->groupBy('kunci')
            ->map(fn ($daftar) => collect($daftar->first())->except('tempat', 'jawab', 'kolom')->all() + ['baris' => $daftar->pluck('tempat')->unique()->values()->all()])
            ->values()
            ->all();
    }
}
