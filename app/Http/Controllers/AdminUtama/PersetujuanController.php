<?php

namespace App\Http\Controllers\AdminUtama;

use App\Exceptions\StokTidakCukup;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\PutuskanDokumenRequest;
use App\Http\Requests\AdminUtama\PutuskanPermintaanRequest;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Opname;
use App\Models\OpnameDetail;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Services\AntreanPersetujuanService;
use App\Services\CetakTransaksiService;
use App\Services\EksporDaftarService;
use App\Services\PersetujuanService;
use App\Services\StokService;
use App\Support\BukuExcel;
use App\Support\DokumenPdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Antrean dan keputusan Admin Utama per dokumen. Antrean disusun AntreanPersetujuanService. */
class PersetujuanController extends Controller
{
    public function __construct(
        private PersetujuanService $persetujuan,
        private StokService $stok,
        private AntreanPersetujuanService $antrean,
    ) {
    }

    public function index(Request $request): View
    {
        $jenis = $request->query('jenis');
        $jenis = is_string($jenis) && array_key_exists($jenis, AntreanPersetujuanService::JENIS) ? $jenis : '';
        $antrean = $this->antrean->daftar($jenis);

        return view('admin-utama.persetujuan.index', [
            'antrean'      => $antrean,
            'jumlahWajar'  => $jenis === '' ? $antrean->where('cek.wajar', true)->count() : $this->antrean->yangWajar()->count(),
            'jenis'        => $jenis,
            'pilihanJenis' => AntreanPersetujuanService::JENIS,
        ]);
    }

    public function permintaan(Permintaan $permintaan): View|RedirectResponse
    {
        $permintaan->load([
            'cabang:id,nama,kode',
            'pengaju:id,nama',
            'detail' => fn ($q) => $q->orderBy('id'),
            'detail.bahanBaku.satuanBeli',
            'detail.bahanBaku.satuanDasar',
            'detail.persetujuan.oleh:id,nama',
        ]);

        $bahanIds = $permintaan->detail->pluck('bahan_baku_id')->all();
        $pusat = Cabang::pusatAktifSelain(null);

        return view('admin-utama.persetujuan.permintaan', [
            'permintaan'  => $permintaan,
            'saldoCabang' => $this->saldo($permintaan->cabang_id, $bahanIds),
            'saldoPusat'  => $pusat ? $this->saldo($pusat->id, $bahanIds) : collect(),
            'pusat'       => $pusat,
            'menunggu'    => $permintaan->detail->where('status', PermintaanDetail::STATUS_MENUNGGU),
            'maksCatatan' => PutuskanPermintaanRequest::MAKS_CATATAN,
            'bisaKirim'   => $pusat !== null && $permintaan->status === Permintaan::STATUS_MENUNGGU
                && $permintaan->detail()->masihUtang()->exists(),
        ]);
    }

    public function putuskanPermintaan(PutuskanPermintaanRequest $request, Permintaan $permintaan): RedirectResponse
    {
        try {
            $hasil = $this->persetujuan->putuskanPermintaan($permintaan, $request->user(), $request->validated('keputusan'));
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.persetujuan.permintaan', $permintaan)->with('galat', $e->getMessage());
        }

        if ($hasil['disetujui'] + $hasil['ditolak'] === 0) {
            return redirect()->route('admin-utama.persetujuan.permintaan', $permintaan)
                ->with('galat', 'Belum ada barang yang diputuskan. Pilih Setujui atau Tolak pada barang yang ingin diputuskan.');
        }

        $pesan = "Permintaan {$permintaan->nomor}: {$hasil['disetujui']} barang disetujui, {$hasil['ditolak']} ditolak.";

        if ($hasil['disetujui'] > 0 && Cabang::pusatAktifSelain(null)) {
            return redirect()->route('admin-utama.kiriman.buat', $permintaan)->with('sukses', $pesan.' Kirim sekarang, atau nanti lewat menu Kiriman.');
        }

        return redirect()->route('admin-utama.persetujuan')->with('sukses', $pesan);
    }

    public function pdfBarangMasuk(Request $request, BarangMasuk $barangMasuk, CetakTransaksiService $cetak): Response
    {
        return DokumenPdf::unduh($cetak->barangMasuk($barangMasuk, $request->user()), $cetak->namaFile('bukti barang masuk', $barangMasuk->nomor));
    }

    public function pdfBarangKeluar(Request $request, BarangKeluar $barangKeluar, CetakTransaksiService $cetak): Response
    {
        return DokumenPdf::unduh($cetak->barangRusak($barangKeluar, $request->user()), $cetak->namaFile('berita acara barang rusak', $barangKeluar->nomor));
    }

    public function pdfOpname(Request $request, Opname $opname, CetakTransaksiService $cetak): Response
    {
        return DokumenPdf::unduh($cetak->opname($opname, $request->user(), lengkap: true), $cetak->namaFile('hasil stock opname', $opname->nomor));
    }

    public function excelOpname(Opname $opname, EksporDaftarService $ekspor): StreamedResponse
    {
        return BukuExcel::unduh($ekspor->hasilOpname($opname, lengkap: true), BukuExcel::namaBerkas('hasil-opname', $opname->nomor));
    }

    public function barangMasuk(BarangMasuk $barangMasuk): View
    {
        $barangMasuk->load([
            'cabang:id,nama',
            'pencatat:id,nama',
            'detail.bahanBaku.satuanBeli',
            'detail.bahanBaku.satuanDasar',
            'persetujuan.oleh:id,nama',
        ]);

        return view('admin-utama.persetujuan.barang-masuk', [
            'dokumen'      => $barangMasuk,
            'saldo'        => $this->saldo($barangMasuk->cabang_id, $barangMasuk->detail->pluck('bahan_baku_id')->all()),
            'pilihanTolak' => self::pilihanTolak(),
            'maksCatatan'  => PutuskanDokumenRequest::MAKS_CATATAN,
        ]);
    }

    public function putuskanBarangMasuk(PutuskanDokumenRequest $request, BarangMasuk $barangMasuk): RedirectResponse
    {
        $aksi = $request->validated('aksi');

        try {
            $this->persetujuan->putuskanBarangMasuk($barangMasuk, $request->user(), $aksi, $request->validated('catatan'));
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.persetujuan.barang-masuk', $barangMasuk)->with('galat', $e->getMessage());
        }

        $pesan = match ($aksi) {
            PersetujuanService::AKSI_SETUJUI => "Barang masuk {$barangMasuk->nomor} disetujui. Stok {$barangMasuk->cabang->nama} sudah bertambah.",
            PersetujuanService::AKSI_REVISI  => "Barang masuk {$barangMasuk->nomor} dikembalikan untuk diperbaiki.",
            default                          => "Barang masuk {$barangMasuk->nomor} ditolak.",
        };

        return redirect()->route('admin-utama.persetujuan')->with('sukses', $pesan);
    }

    public function barangKeluar(BarangKeluar $barangKeluar): View
    {
        $barangKeluar->load([
            'cabang:id,nama',
            'pencatat:id,nama',
            'detail.bahanBaku.satuanBeli',
            'persetujuan.oleh:id,nama',
        ]);

        return view('admin-utama.persetujuan.barang-keluar', [
            'dokumen'      => $barangKeluar,
            'saldo'        => $this->saldo($barangKeluar->cabang_id, $barangKeluar->detail->pluck('bahan_baku_id')->all()),
            'pilihanTolak' => self::pilihanTolak(),
            'maksCatatan'  => PutuskanDokumenRequest::MAKS_CATATAN,
        ]);
    }

    public function putuskanBarangKeluar(PutuskanDokumenRequest $request, BarangKeluar $barangKeluar): RedirectResponse
    {
        $aksi = $request->validated('aksi');

        try {
            $this->persetujuan->putuskanBarangKeluar($barangKeluar, $request->user(), $aksi, $request->validated('catatan'));
        } catch (StokTidakCukup $e) {
            return redirect()->route('admin-utama.persetujuan.barang-keluar', $barangKeluar)->with(
                'galat',
                "Stok {$e->namaBahan} yang tercatat sekarang lebih kecil dari jumlah yang dikeluarkan. Kembalikan ke pencatat untuk diperiksa."
            );
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.persetujuan.barang-keluar', $barangKeluar)->with('galat', $e->getMessage());
        }

        $pesan = match ($aksi) {
            PersetujuanService::AKSI_SETUJUI => "Barang rusak {$barangKeluar->nomor} disetujui. Stok {$barangKeluar->cabang->nama} sudah dikurangi.",
            PersetujuanService::AKSI_REVISI  => "Barang rusak {$barangKeluar->nomor} dikembalikan untuk diperbaiki.",
            default                          => "Barang rusak {$barangKeluar->nomor} ditolak.",
        };

        return redirect()->route('admin-utama.persetujuan')->with('sukses', $pesan);
    }

    public function opname(Opname $opname): View
    {
        $opname->load(['cabang:id,nama', 'petugas:id,nama', 'persetujuan.oleh:id,nama']);

        $detail = $opname->detail()
            ->with(['bahanBaku:id,nama,foto,satuan_beli_id,isi_per_satuan_beli,toleransi_persen', 'bahanBaku.satuanBeli:id,nama'])
            ->get();

        $sesuai = $detail->filter(fn (OpnameDetail $d) => (float) $d->qty_fisik === (float) $d->qty_sistem);
        $luar = $detail->filter(fn (OpnameDetail $d) => $d->lewatToleransi());

        return view('admin-utama.persetujuan.opname', [
            'opname'       => $opname,
            'detail'       => $detail
                ->sortBy(fn (OpnameDetail $d) => [$d->lewatToleransi() ? 0 : 1, -abs((float) $d->qty_fisik - (float) $d->qty_sistem), $d->bahanBaku->nama])
                ->values(),
            'ringkasan'    => [
                'sesuai' => $sesuai->count(),
                'luar'   => $luar->count(),
                'dalam'  => $detail->count() - $sesuai->count() - $luar->count(),
            ],
            'pilihanTolak' => [
                PersetujuanService::AKSI_REVISI => 'Minta Hitung Ulang',
                PersetujuanService::AKSI_TOLAK  => 'Tolak tanpa Koreksi Stok',
            ],
            'maksCatatan'  => PutuskanDokumenRequest::MAKS_CATATAN,
        ]);
    }

    public function putuskanOpname(PutuskanDokumenRequest $request, Opname $opname): RedirectResponse
    {
        $aksi = $request->validated('aksi');

        try {
            $this->persetujuan->putuskanOpname($opname, $request->user(), $aksi, $request->validated('catatan'));
        } catch (DomainException $e) {
            return redirect()->route('admin-utama.persetujuan.opname', $opname)->with('galat', $e->getMessage());
        }

        $pesan = match ($aksi) {
            PersetujuanService::AKSI_SETUJUI => "Opname {$opname->nomor} disetujui. Stok {$opname->cabang->nama} sudah dikoreksi dan tidak lagi dikunci.",
            PersetujuanService::AKSI_REVISI  => "Opname {$opname->nomor} dikembalikan untuk dihitung ulang.",
            default                          => "Opname {$opname->nomor} ditolak. Stok tidak dikoreksi.",
        };

        return redirect()->route('admin-utama.persetujuan')->with('sukses', $pesan);
    }

    /** Pilihan bila dokumen tidak disetujui. */
    private static function pilihanTolak(): array
    {
        return [
            PersetujuanService::AKSI_REVISI => 'Kembalikan',
            PersetujuanService::AKSI_TOLAK  => 'Tolak dan Batalkan',
        ];
    }

    private function saldo(int $lokasiId, array $bahanIds): Collection
    {
        return collect($bahanIds)->mapWithKeys(fn (int $id) => [$id => $this->stok->saldo($lokasiId, $id)]);
    }
}
