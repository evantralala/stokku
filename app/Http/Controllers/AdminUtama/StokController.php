<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Concerns\PantauStok;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\KosongkanStokRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\StokMutasi;
use App\Services\PenyesuaianStokService;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Pantauan stok untuk Admin Utama: semua lokasi, satu lokasi per tampilan (?lokasi=). */
class StokController extends Controller
{
    use PantauStok;

    protected string $rute = 'admin-utama.stok';

    protected function bolehPilihLokasi(): bool
    {
        return true;
    }

    /** Lokasi dari ?lokasi=; bawaan Gudang Pusat aktif, atau lokasi aktif pertama. */
    protected function lokasiPantauan(Request $request): Cabang
    {
        $id = (int) $request->query('lokasi');

        $lokasi = ($id ? Cabang::query()->aktif()->find($id) : null)
            ?? Cabang::pusatAktifSelain(null)
            ?? Cabang::query()->aktif()->orderBy('nama')->first();

        if (! $lokasi) {
            throw new HttpResponseException(redirect()->route('admin-utama.lokasi')
                ->with('galat', 'Belum ada lokasi aktif. Tambahkan Gudang Pusat dan cabang dulu, lalu buka Stok lagi.'));
        }

        return $lokasi;
    }

    /**
     * Popup Kosongkan Stok untuk bahan yang stoknya tidak 0 di lokasi ini.
     * ?bahan=ID (ikon sampah di baris) = satu bahan saja; ?bahan[]=... (mode pilih di halaman Stok) = bahan yang dicentang;
     * tanpa bahan = semua bahan per kategori, belum ada yang tercentang (tanpa JavaScript).
     */
    public function kosongkan(Request $request): View
    {
        $lokasi = $this->lokasiPantauan($request);
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('saldo', '!=', 0)->pluck('saldo', 'bahan_baku_id');
        $pilihan = collect((array) $request->query('bahan'))->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($pilihan->isNotEmpty()) {
            $saldo = $saldo->only($pilihan->all());
        }

        $daftar = BahanBaku::query()
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama'])
            ->whereKey($saldo->keys()->all())
            ->get()
            ->map(fn (BahanBaku $b) => ['bahan' => $b, 'saldo' => (float) $saldo[$b->id]])
            ->sortBy(fn (array $r) => [$r['bahan']->kategori->urutan, $r['bahan']->kategori->nama, $r['bahan']->nama])
            ->groupBy(fn (array $r) => $r['bahan']->kategori->nama);

        return view('stok.kosongkan', [
            'lokasi'          => $lokasi,
            'daftar'          => $daftar,
            'dipilih'         => $pilihan->isNotEmpty(),
            'satu'            => $pilihan->count() === 1 && $saldo->count() === 1,
            'menunggu'        => StokMutasi::query()->menunggu()->where('cabang_id', $lokasi->id)->distinct()->count('bahan_baku_id'),
            'kata'            => KosongkanStokRequest::KATA_KONFIRMASI,
            'maksAlasan'      => KosongkanStokRequest::MAKS_ALASAN,
            'parameterLokasi' => ['lokasi' => $lokasi->id],
        ]);
    }

    public function simpanKosongkan(KosongkanStokRequest $request, PenyesuaianStokService $layanan): RedirectResponse
    {
        $lokasi = $this->lokasiPantauan($request);
        $kembali = redirect()->route($this->rute, ['lokasi' => $lokasi->id]);

        try {
            $jumlah = $layanan->kosongkan($request->user(), $lokasi, $request->validated('bahan'), $request->validated('alasan'));
        } catch (DomainException $e) {
            return $kembali->with('galat', $e->getMessage());
        }

        return $kembali->with('sukses', "Stok {$jumlah} bahan di {$lokasi->nama} dikosongkan. Tercatat di riwayat stok sebagai penyesuaian.");
    }
}
