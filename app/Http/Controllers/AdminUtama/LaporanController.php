<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Services\EksporLaporanService;
use App\Services\LaporanService;
use App\Support\BukuExcel;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Admin Utama. Saringan bersama: ?dari=&sampai= (periode) dan ?lokasi= (kosong = semua lokasi).
 * Halaman dan berkas Excel memakai data yang sama dari kumpulkan().
 */
class LaporanController extends Controller
{
    public const JENIS = [
        'stok'      => ['Rekap Stok', 'Stok awal, keluar-masuk, dan stok akhir per lokasi.'],
        'penjualan' => ['Penjualan & Pemakaian', 'Porsi per menu dan ke mana bahan pergi.'],
        'kategori'  => ['Per Kategori', 'Penjualan per kategori menu dan pemakaian per kategori bahan.'],
        'selisih'   => ['Selisih Kiriman & Opname', 'Jumlah yang tidak cocok saat diterima atau dihitung.'],
        'rusak'     => ['Barang Rusak', 'Per bahan.'],
    ];

    /** Ikon tiap laporan di halaman daftar laporan (nama di components/ikon). */
    public const IKON = [
        'stok'      => 'kotak',
        'penjualan' => 'grafik',
        'kategori'  => 'label',
        'selisih'   => 'timbangan',
        'rusak'     => 'keluar',
    ];

    /** Laporan yang wajib satu lokasi. */
    private const WAJIB_LOKASI = ['stok'];

    /** Laporan yang bisa disaring per kategori bahan. */
    private const SARING_KATEGORI = ['stok', 'penjualan', 'kategori'];

    public function __construct(private LaporanService $laporan) {}

    public function index(): View
    {
        return view('admin-utama.laporan.index', [
            'jenis' => self::JENIS,
            'ikon'  => self::IKON,
        ]);
    }

    public function stok(Request $request): View
    {
        return $this->tampil('stok', $request);
    }

    public function penjualan(Request $request): View
    {
        return $this->tampil('penjualan', $request);
    }

    public function kategori(Request $request): View
    {
        return $this->tampil('kategori', $request);
    }

    public function selisih(Request $request): View
    {
        return $this->tampil('selisih', $request);
    }

    public function rusak(Request $request): View
    {
        return $this->tampil('rusak', $request);
    }

    /** Unduh laporan sebagai Excel dengan saringan yang sama seperti halaman. */
    public function unduh(Request $request, EksporLaporanService $ekspor, string $jenis): StreamedResponse
    {
        abort_unless(array_key_exists($jenis, self::JENIS), 404);

        ['periode' => $periode, 'lokasi' => $lokasi, 'kategori' => $kategori, 'data' => $data] = $this->kumpulkan($jenis, $request);

        $judul = self::JENIS[$jenis][0].($kategori ? ' · '.$kategori->nama : '');
        $namaLokasi = $lokasi?->nama ?? 'Semua Lokasi';
        $buku = $ekspor->buat($jenis, $judul, $periode, $namaLokasi, $data);

        return BukuExcel::unduh($buku, EksporLaporanService::namaBerkas($judul, $periode, $namaLokasi));
    }

    private function tampil(string $jenis, Request $request): View
    {
        ['periode' => $periode, 'lokasi' => $lokasi, 'kategori' => $kategori, 'data' => $data] = $this->kumpulkan($jenis, $request);

        return view('admin-utama.laporan.'.$jenis, $data + [
            'kategori'        => $kategori,
            'pilihanKategori' => in_array($jenis, self::SARING_KATEGORI, true)
                ? Kategori::query()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id')
                : null,
            'jenis'         => $jenis,
            'judul'         => self::JENIS[$jenis][0],
            'periode'       => $periode,
            'lokasi'        => $lokasi,
            'pilihanLokasi' => $this->pilihanLokasi(),
            'wajibLokasi'   => in_array($jenis, self::WAJIB_LOKASI, true),
        ]);
    }

    /** @return array{periode: Periode, lokasi: ?Cabang, kategori: ?Kategori, data: array} */
    private function kumpulkan(string $jenis, Request $request): array
    {
        $periode = Periode::dariRequest($request);
        $lokasi = $this->lokasi($request);

        $kategori = in_array($jenis, self::SARING_KATEGORI, true) ? Kategori::query()->find((int) $request->query('kategori')) : null;

        if (in_array($jenis, self::WAJIB_LOKASI, true)) {
            $lokasi ??= Cabang::pusatAktifSelain(null) ?? Cabang::query()->orderBy('nama')->first();
        }

        $data = match ($jenis) {
            'stok' => [
                'baris' => $rekap = $lokasi ? $this->laporan->rekapStok($lokasi, $periode, $kategori?->id) : collect(),
                'kolom' => LaporanService::kolomRekap($rekap),
            ],
            'penjualan' => [
                'menu'  => $this->laporan->penjualanMenu($lokasi, $periode),
                'bahan' => $this->laporan->pemakaianBahan($lokasi, $periode, $kategori?->id),
            ],
            'kategori' => [
                'perMenu'  => LaporanService::penjualanPerKategori($this->laporan->penjualanMenu($lokasi, $periode)),
                'perBahan' => LaporanService::pemakaianPerKategori($this->laporan->pemakaianBahan($lokasi, $periode, $kategori?->id)),
            ],
            'selisih' => [
                'kiriman' => $this->laporan->selisihKiriman($lokasi, $periode),
                'opname'  => $this->laporan->selisihOpname($lokasi, $periode),
            ],
            'rusak'    => $this->laporan->barangRusak($lokasi, $periode),
        };

        return ['periode' => $periode, 'lokasi' => $lokasi, 'kategori' => $kategori, 'data' => $data];
    }

    private function lokasi(Request $request): ?Cabang
    {
        $id = (int) $request->query('lokasi');

        return $id ? Cabang::query()->find($id) : null;
    }

    private function pilihanLokasi(): Collection
    {
        return Cabang::query()->orderByDesc('jenis')->orderBy('nama')->pluck('nama', 'id');
    }
}
