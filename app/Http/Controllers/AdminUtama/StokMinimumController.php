<?php

namespace App\Http\Controllers\AdminUtama;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUtama\StokMinimumRequest;
use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\LogPerubahan;
use App\Services\LogPerubahanService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Stok minimum bahan baku per lokasi. Satu layar menampilkan satu lokasi;
 * semua lokasi aktif (termasuk lokasi baru) otomatis tersedia di pilihan.
 */
class StokMinimumController extends Controller
{
    public function index(Request $request): View
    {
        $daftarLokasi = Cabang::query()
            ->aktif()
            ->orderByRaw('jenis = ? desc', [Cabang::JENIS_PUSAT])
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama', 'jenis']);

        $lokasi = $daftarLokasi->firstWhere('id', (int) $request->query('lokasi')) ?? $daftarLokasi->first();

        $filter = [
            'cari'     => trim((string) $request->query('cari', '')),
            'kategori' => (int) $request->query('kategori') ?: null,
        ];

        $bahan = $lokasi ? $this->bahanUntuk($filter) : collect();

        $minimum = $lokasi
            ? BahanBakuMinimum::where('cabang_id', $lokasi->id)->pluck('minimum', 'bahan_baku_id')
            : collect();

        return view('admin-utama.stok-minimum.index', [
            'daftarLokasi'    => $daftarLokasi,
            'lokasi'          => $lokasi,
            'filter'          => $filter,
            'kelompok'        => $bahan->groupBy(fn (BahanBaku $b) => $b->kategori->nama),
            'minimum'         => $minimum,
            'jumlahTerisi'    => $minimum->count(),
            'jumlahBahan'     => BahanBaku::aktif()->count(),
            'pilihanKategori' => Kategori::query()->aktif()->orderBy('urutan')->orderBy('nama')->pluck('nama', 'id'),
        ]);
    }

    public function simpan(StokMinimumRequest $request, LogPerubahanService $log): RedirectResponse
    {
        $lokasi = Cabang::findOrFail($request->integer('lokasi_id'));
        $jumlah = BahanBakuMinimum::simpanUntukLokasi($lokasi->id, $request->validated('minimum'));
        $log->catat($request->user(), LogPerubahan::KATEGORI_DATA, 'stok-minimum.simpan', "Stok minimum {$lokasi->nama} disimpan: {$jumlah} bahan", [], $lokasi);

        return redirect()
            ->route('admin-utama.stok-minimum', ['lokasi' => $lokasi->id] + $request->only('cari', 'kategori'))
            ->with('sukses', "Stok minimum {$jumlah} bahan di {$lokasi->nama} berhasil disimpan.");
    }

    public function salin(Request $request, LogPerubahanService $log): RedirectResponse
    {
        $lokasiAktif = Rule::exists('cabang', 'id')->where('aktif', true);

        $data = $request->validate([
            'ke_lokasi_id'   => ['required', 'integer', $lokasiAktif],
            'dari_lokasi_id' => ['required', 'integer', 'different:ke_lokasi_id', $lokasiAktif],
        ], [
            'dari_lokasi_id.required'  => 'Pilih lokasi yang ingin disalin.',
            'dari_lokasi_id.different' => 'Pilih lokasi lain sebagai sumber salinan.',
            '*.exists'                 => 'Lokasi tidak ditemukan atau sudah tidak aktif.',
        ]);

        $dari = Cabang::findOrFail($data['dari_lokasi_id']);
        $ke = Cabang::findOrFail($data['ke_lokasi_id']);
        $jumlah = BahanBakuMinimum::salin($dari->id, $ke->id);
        $log->catat($request->user(), LogPerubahan::KATEGORI_DATA, 'stok-minimum.salin', "Stok minimum {$ke->nama} disalin dari {$dari->nama}: {$jumlah} bahan", [], $ke);

        return redirect()
            ->route('admin-utama.stok-minimum', ['lokasi' => $ke->id])
            ->with('sukses', "Stok minimum {$ke->nama} diganti dengan milik {$dari->nama} ({$jumlah} bahan).");
    }

    /** Bahan aktif sesuai filter, urut kategori lalu nama. */
    private function bahanUntuk(array $filter): Collection
    {
        return BahanBaku::query()
            ->aktif()
            ->cari($filter['cari'])
            ->when($filter['kategori'], fn (Builder $q, int $id) => $q->where('kategori_id', $id))
            ->with(['kategori:id,nama,urutan', 'satuanBeli:id,nama'])
            ->orderBy(Kategori::select('urutan')->whereColumn('kategori.id', 'bahan_baku.kategori_id'))
            ->orderBy('nama')
            ->get(['id', 'nama', 'kategori_id', 'satuan_beli_id', 'isi_per_satuan_beli']);
    }
}
