<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\AdminUtama\ImporDataRequest;
use App\Observers\CatatPerubahanData;
use App\Services\Impor\ImporExcel;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Alur impor Excel yang sama untuk semua halaman: unggah (popup), templat, pratinjau, simpan, ekspor.
 * Isi file dibersihkan dulu (PembersihImpor); jawaban "Maksudnya …?" dikirim bersama tombol Simpan (tebakan[kunci]).
 * $url berisi alamat templat, pratinjau, simpan, dan kembali (daftar asal).
 */
trait AlurImporExcel
{
    private const AWALAN_SESI = 'impor_excel.';

    /** @param  array{templat: string, pratinjau: string, simpan: string, kembali: string}  $url */
    protected function tampilUnggah(ImporExcel $layanan, array $url): View
    {
        return view('admin-utama.excel.unggah', [
            'judul' => $layanan->judul(),
            'teks'  => $layanan->teksUnggah(),
            'kolom' => $layanan->kolom(),
            'url'   => $url,
            'pilihanMode' => $layanan->pilihanMode(),
            'mode'  => $layanan->mode(),
        ]);
    }

    protected function unduhTemplat(ImporExcel $layanan): StreamedResponse
    {
        return $this->unduhBuku($layanan->templat(), $layanan->namaFile('templat'));
    }

    protected function unduhEkspor(ImporExcel $layanan): StreamedResponse
    {
        return $this->unduhBuku($layanan->ekspor(), $layanan->namaFile('ekspor'));
    }

    /** @param  array{templat: string, pratinjau: string, simpan: string, kembali: string}  $url */
    protected function tampilPratinjau(ImporDataRequest $request, ImporExcel $layanan, string $kunciSesi, array $url): View|RedirectResponse
    {
        $file = $request->file('file');
        $layanan->pakaiMode($request->input('mode'));

        try {
            $mentah = $layanan->bacaFile($file);
        } catch (DomainException $e) {
            return redirect($url['kembali'])->with('galat', $e->getMessage());
        }

        $baris = $layanan->periksaBersih($mentah);
        $kunci = Str::random(20);

        $request->session()->put(self::AWALAN_SESI.$kunciSesi, [
            'kunci'     => $kunci,
            'mentah'    => $mentah,
            'nama_file' => $file->getClientOriginalName(),
            'mode'      => $layanan->mode(),
        ]);

        $status = [ImporExcel::STATUS_SALAH, ImporExcel::STATUS_CEK, ImporExcel::STATUS_SIAP, ImporExcel::STATUS_DILEWATI];
        $semua = collect($baris);

        return view('admin-utama.excel.pratinjau', [
            'layanan'  => $layanan,
            'url'      => $url,
            'baris'    => $semua->sortBy(fn ($r) => [array_search($r['status'], $status, true), $r['betul'] === [] ? 1 : 0, $r['baris']])->values(),
            'hitung'   => $semua->countBy('status')->all() + array_fill_keys($status, 0),
            'dibetulkan' => $semua->where('status', ImporExcel::STATUS_SIAP)->filter(fn ($r) => $r['betul'] !== [])->count(),
            'tebakan'  => $this->daftarTebakan($semua),
            'kunci'    => $kunci,
            'namaFile' => $file->getClientOriginalName(),
            'contohDilewati' => $layanan->contohDilewati(),
            'perkiraan' => $layanan->perkiraanStok($baris),
            'catatanPratinjau' => $layanan->catatanPratinjau($baris),
            'labelMode' => $layanan->mode() ? $layanan->pilihanMode()[$layanan->mode()] : null,
        ]);
    }

    protected function simpanImpor(Request $request, ImporExcel $layanan, string $kunciSesi, string $kembali): RedirectResponse
    {
        $sesi = $request->session()->pull(self::AWALAN_SESI.$kunciSesi);
        $tujuan = redirect($kembali);

        if (! $sesi || ! hash_equals($sesi['kunci'], (string) $request->input('kunci'))) {
            return $tujuan->with('galat', 'Pratinjau sudah tidak berlaku. Unggah ulang filenya.');
        }

        $layanan->pakaiMode($sesi['mode'] ?? null);

        try {
            $keputusan = array_filter((array) $request->input('tebakan', []), fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
            $hasil = CatatPerubahanData::diam(fn () => $layanan->simpanBersih($sesi['mentah'], $keputusan, $request->user(), $sesi['nama_file']));
        } catch (DomainException $e) {
            return $tujuan->with('galat', $e->getMessage());
        }

        if ($hasil['disimpan'] === 0) {
            return $tujuan->with('galat', 'Tidak ada baris yang bisa disimpan. Cek lagi isi filenya.');
        }

        return $tujuan->with('sukses', $layanan->pesanSimpan($hasil));
    }

    /**
     * Tebakan unik (satu pertanyaan untuk tulisan yang sama di beberapa baris).
     *
     * @return list<array{kunci: string, label: string, asli: string, saran: string, ada: bool, baris: list<int>}>
     */
    private function daftarTebakan(\Illuminate\Support\Collection $baris): array
    {
        return $baris->flatMap(fn (array $r) => collect($r['tebakan'])->map(fn (array $t) => $t + ['nomor' => $r['baris']]))
            ->groupBy('kunci')
            ->map(fn ($daftar) => collect($daftar->first())->except('nomor', 'jawab', 'kolom')->all() + ['baris' => $daftar->pluck('nomor')->unique()->sort()->values()->all()])
            ->sortBy(fn (array $t) => $t['baris'][0])
            ->values()
            ->all();
    }

    private function unduhBuku(Spreadsheet $buku, string $nama): StreamedResponse
    {
        return response()->streamDownload(
            fn () => (new Xlsx($buku))->save('php://output'),
            $nama,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
