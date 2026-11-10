<?php

namespace App\Services\Impor;

use App\Http\Requests\Pusat\BarangMasukRequest;
use App\Http\Requests\TambahStokRequest;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\User;
use App\Services\LogPerubahanService;
use App\Services\EksporDaftarService;
use App\Services\NotifikasiService;
use App\Services\PantauanStokService;
use App\Services\PenyesuaianStokService;
use App\Services\TambahStokService;
use App\Support\Angka;
use App\Support\BukuExcel;
use App\Support\LembarExcel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Pembaruan jumlah stok satu lokasi lewat Excel ("universal"): file Perbarui Lewat Excel, file Unduh Excel halaman Stok,
 * atau tabel buatan sendiri. Baris judul dicari sendiri dan kolom dikenali dari namanya (Bahan/Item, Stok/Qty/Jumlah,
 * Satuan/Unit, Lokasi/Gudang/Cabang); kolom lain (Status, Minimum, Menunggu, ...) diabaikan.
 *
 * Dua cara simpan dipilih saat unggah (pilihanMode):
 * - Tambah (bawaan): angka di kolom Stok ditambahkan ke stok sekarang dan dicatat sebagai tambah stok
 *   (TambahStokService, jenis tambah langsung), termasuk file Unduh Excel yang diunggah tanpa diubah (stok menjadi dua kali).
 * - Ganti: stok diganti angka di file (kolom Stok Sebenarnya, Stok/Qty, atau Stok Sistem yang diubah) dan dicatat
 *   sebagai penyesuaian stok (PenyesuaianStokService). Bila stok sempat berubah setelah file diunduh (lembar tersembunyi
 *   "Data Sistem"), baris tetap disimpan dengan catatan.
 * Semua baris disimpan dalam satu transaksi.
 */
class ImporPenyesuaianStokService extends ImporExcel
{
    public const MAKS_BARIS = 5000;

    private const TOLERANSI = 0.0005;

    /** Jenis lembar "Data Sistem" berisi stok saat file diunduh: info {lokasi, waktu}, isi [nama bahan, stok satuan beli]. */
    public const DATA_SISTEM = 'stok';

    /** Cara simpan: angka di file ditambahkan ke stok (barang datang), atau stok disamakan dengan angka di file. */
    public const MODE_TAMBAH = 'tambah';
    public const MODE_SAMAKAN = 'samakan';

    private ?Cabang $lokasi = null;

    public function __construct(
        LogPerubahanService $log,
        private PenyesuaianStokService $penyesuaian,
        private PantauanStokService $pantauan,
        private NotifikasiService $notifikasi,
        private TambahStokService $tambahStok,
    ) {
        parent::__construct($log);
    }

    /** Lokasi yang stoknya diperbarui. Wajib dipanggil sebelum templat, ekspor, periksa, atau simpan. */
    public function diLokasi(Cabang $lokasi): static
    {
        $this->lokasi = $lokasi;

        return $this;
    }

    public function judul(): string
    {
        return 'Stok';
    }

    public function satuanHasil(): string
    {
        return 'bahan diperbarui';
    }

    public function pilihanMode(): array
    {
        return [
            self::MODE_TAMBAH  => ['Tambah'],
            self::MODE_SAMAKAN => ['Ganti'],
        ];
    }

    private function tambah(): bool
    {
        return $this->mode() === self::MODE_TAMBAH;
    }

    /** Angka di file untuk mode Tambah: kolom Stok Sebenarnya atau Stok (atau Qty/Jumlah). */
    private function bacaTambah(array $isi): mixed
    {
        foreach (['stok_sebenarnya', 'stok'] as $kolom) {
            if (filled($isi[$kolom] ?? null)) {
                return $isi[$kolom];
            }
        }

        return null;
    }

    public function pesanSimpan(array $hasil): string
    {
        $pesan = 'Stok '.$hasil['disimpan'].' bahan '.($this->tambah() ? 'ditambah.' : 'diperbarui.');

        return $hasil['dilewati'] > 0 ? $pesan.' '.$hasil['dilewati'].' baris tidak diubah.' : $pesan;
    }

    public function teksUnggah(): array
    {
        return [
            'Perbarui Lewat Excel',
            'Unduh templat atau file Unduh Excel '.$this->lokasiDipakai()->nama.', isi angka di kolom Stok, lalu unggah di sini. Tabel sendiri juga bisa, asal ada kolom nama bahan dan stok.',
            'Unduh Templat',
        ];
    }

    public function labelDilewati(): string
    {
        return 'Tidak berubah';
    }

    /** Kolom yang dibaca dari file Unduh Excel halaman Stok (kolom lain seperti Menunggu, Minimum, Status diabaikan). */
    public function kolom(): array
    {
        return [
            'kategori'   => ['lebar' => 16, 'ket' => 'Hanya keterangan, tidak dibaca.', 'contoh' => 'Bahan Pokok', 'info' => true],
            'bahan'      => ['lebar' => 28, 'ket' => 'Wajib. Nama bahan baku seperti di daftar bahan. Sudah terisi dari file.', 'contoh' => 'Beras Jepang'],
            'satuan'     => ['lebar' => 10, 'ket' => 'Satuan bahan, mis. kg. Bila diisi gram atau ml, angkanya dikonversi otomatis.', 'contoh' => 'kg'],
            'stok'       => ['lebar' => 12, 'ket' => 'Ubah angka bahan yang stoknya berubah, mis. 7,5. Angka yang tidak diubah dilewati.', 'contoh' => 10],
            'keterangan' => ['lebar' => 24, 'ket' => 'Boleh kosong, maksimal '.TambahStokRequest::MAKS_KETERANGAN.' karakter, mis. hitung ulang rak. Muncul di riwayat stok.', 'contoh' => 'Hitung ulang rak'],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['bahan', ['stok', 'stok_sebenarnya', 'stok_sistem']];
    }

    /** Stok Sistem / Stok Sebenarnya: file templat lama (sebelum disamakan dengan Unduh Excel) tetap terbaca. */
    protected function kolomTambahan(): array
    {
        return ['stok_sistem', 'stok_sebenarnya', 'lokasi'];
    }

    protected function kolomJumlahPintar(): array
    {
        return ['stok_sebenarnya', 'stok'];
    }

    /** Bahan yang sama di beberapa baris (lokasi sama) dijumlahkan, mis. stok rak A dan rak B. */
    protected function kunciGabung(array $isi): ?string
    {
        $bahan = mb_strtolower(Str::squish((string) ($isi['bahan'] ?? '')));

        return $bahan === '' ? null : mb_strtolower(Str::squish((string) ($isi['lokasi'] ?? ''))).'|'.$bahan;
    }

    public function jenisKolom(): array
    {
        return ['satuan' => KamusImpor::SATUAN, 'stok_unduh' => 'angka'] + parent::jenisKolom();
    }

    /** Baris file ditambah 'stok_unduh': stok bahan itu saat file diunduh (dari lembar Data Sistem), bila ada. */
    public function bacaFile(UploadedFile $file): array
    {
        $mentah = parent::bacaFile($file);
        $sistem = LembarExcel::bacaDataSistem($file, self::DATA_SISTEM);
        $stokUnduh = [];

        if ($sistem && $this->lokasi && (int) ($sistem['info']['lokasi'] ?? 0) === $this->lokasi->id) {
            foreach ($sistem['isi'] as [$nama, $stok]) {
                $stokUnduh[mb_strtolower(Str::squish((string) $nama))] = is_numeric($stok) ? (float) $stok : null;
            }
        }

        foreach ($mentah as $no => $isi) {
            $mentah[$no]['stok_unduh'] = $stokUnduh[mb_strtolower(Str::squish((string) $isi['bahan']))] ?? null;
        }

        return $mentah;
    }

    /**
     * Lembar tersembunyi berisi stok saat diunduh, dipakai juga file Unduh Excel halaman Stok.
     *
     * @param  iterable<array{0: string, 1: float}>  $stok  [nama bahan, stok dalam satuan beli]
     */
    public static function tulisStokUnduh(Spreadsheet $buku, Cabang $lokasi, iterable $stok): void
    {
        LembarExcel::tulisDataSistem($buku, self::DATA_SISTEM, ['lokasi' => $lokasi->id, 'waktu' => now()->toIso8601String()], collect($stok)->values()->all());
    }

    /** Templat (tombol Unduh Templat di popup Perbarui Lewat Excel): format sama dengan Unduh Excel halaman Stok, berisi beberapa bahan per kategori dengan angka contoh. */
    public function templat(): Spreadsheet
    {
        return app(EksporDaftarService::class)->stok($this->lokasiDipakai(), contoh: true);
    }

    public function namaFile(string $jenis): string
    {
        return BukuExcel::namaBerkas('stok', $this->lokasiDipakai()->nama, now()->toDateString());
    }

    protected function barisEkspor(): array
    {
        return PantauanStokService::kelompokkanPerKategori($this->pantauan->daftar($this->lokasiDipakai()))
            ->flatMap(fn (array $k) => $k['baris']->sortBy(fn (array $r) => $r['bahan']->nama))
            ->filter(fn (array $r) => $r['bahan']->aktif)
            ->map(fn (array $r) => [
                $r['bahan']->kategori->nama, $r['bahan']->nama, $r['bahan']->satuanBeli->nama,
                $r['bahan']->keSatuanBeli($r['saldo']), null,
            ])
            ->values()
            ->all();
    }

    public function periksa(array $mentah): array
    {
        $lokasi = $this->lokasiDipakai();
        $bahan = BahanBaku::query()->with('satuanBeli:id,nama', 'satuanDasar:id,nama')->get()->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
        $saldo = DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->pluck('saldo', 'bahan_baku_id');
        $namaLokasi = [mb_strtolower($lokasi->kode), mb_strtolower($lokasi->nama)];
        $diFile = [];
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $namaBahan = Str::squish((string) $isi['bahan']);
            $b = $bahan[mb_strtolower($namaBahan)] ?? null;
            $sekarang = $b ? round((float) ($saldo[$b->id] ?? 0), 3) : 0.0;
            [$baru, $dasar, $catatan, $kosong] = $this->bacaStok($isi);
            $teksLokasi = Str::squish((string) ($isi['lokasi'] ?? ''));

            $data = [
                'bahan'               => $b?->nama ?? $namaBahan,
                'bahan_baku_id'       => $b?->id,
                'satuan'              => $b?->satuanBeli->nama,
                'stok_lama'           => $b ? $b->keSatuanBeli($sekarang) : null,
                'stok_lama_dasar'     => $sekarang,
                'stok_baru'           => is_numeric($baru) ? round((float) $baru, 3) : null,
                'keterangan'          => self::teks($isi['keterangan'] ?? null),
            ];

            $semua = LokasiImpor::jenisSemua($teksLokasi);
            $untukLokasiIni = $teksLokasi === '' || in_array(mb_strtolower($teksLokasi), $namaLokasi, true)
                || $semua === LokasiImpor::SEMUA || ($semua === LokasiImpor::SEMUA_CABANG && ! $lokasi->isPusat());

            if (! $untukLokasiIni) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ["Baris untuk lokasi {$teksLokasi}, bukan {$lokasi->nama}."], 'catatan' => []];

                continue;
            }

            if ($this->tambah()) {
                $hasil[] = $this->periksaTambah($no, $isi, $b, $namaBahan, $data, $diFile);

                continue;
            }

            if ($kosong !== null) {
                $status = $b && $kosong !== 'Stok sebenarnya tidak diisi.' ? self::STATUS_SALAH : self::STATUS_DILEWATI;
                $hasil[] = ['baris' => $no, 'status' => $status, 'data' => $data, 'pesan' => [$kosong], 'catatan' => []];

                continue;
            }

            $pesan = [];

            if (! $b) {
                $pesan[] = $namaBahan === '' ? 'Nama bahan wajib diisi.' : "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
            } elseif (! $b->aktif) {
                $pesan[] = "Bahan {$b->nama} sedang nonaktif.";
            } elseif (isset($diFile[$b->id])) {
                $pesan[] = 'Bahan sama dengan baris '.$diFile[$b->id].'.';
            }

            if ($b) {
                $diFile[$b->id] ??= $no;
            }

            if ($b && $pesan === []) {
                [$data['stok_baru'], $pesanSatuan, $catatanSatuan] = $this->keSatuanBeli($b, $data['stok_baru'], (string) ($isi['satuan'] ?? ''));
                $pesan = [...$pesan, ...$pesanSatuan];
                $catatan = [...$catatan, ...$catatanSatuan];
            }

            if (! is_numeric($data['stok_baru']) || $data['stok_baru'] < 0 || $data['stok_baru'] > BarangMasukRequest::MAKS_QTY) {
                $pesan[] = 'Stok baru harus angka 0 atau lebih, mis. 7,5.';
            }

            if ($b && $pesan === [] && $dasar !== null && abs(round($dasar, 3) - $data['stok_lama']) > self::TOLERANSI) {
                $catatan[] = 'Stok sempat diubah di sistem setelah file diunduh ('.Angka::tampil($dasar).' → '
                    .Angka::tampil($data['stok_lama']).' '.$data['satuan'].'). Bila disimpan, diganti dengan angka di file.';
            }

            if ($b && $pesan === [] && abs($b->keSatuanDasar($data['stok_baru']) - $sekarang) <= self::TOLERANSI) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ['Sama dengan stok sekarang.'], 'catatan' => []];

                continue;
            }

            if (mb_strlen((string) $data['keterangan']) > TambahStokRequest::MAKS_KETERANGAN) {
                $pesan[] = 'Keterangan maksimal '.TambahStokRequest::MAKS_KETERANGAN.' karakter.';
            }

            $hasil[] = [
                'baris'   => $no,
                'status'  => $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH,
                'data'    => $data,
                'pesan'   => $pesan,
                'catatan' => $pesan === [] ? $catatan : [],
            ];
        }

        return $hasil;
    }

    /**
     * Satu baris mode Tambah: angka di file ditambahkan ke stok sekarang (stok_baru = stok_lama + jumlah).
     * Angka yang masih sama dengan stok saat file Unduh Excel diunduh dilewati, supaya file stok yang tidak diubah
     * tidak menggandakan stok.
     *
     * @param  array<int, int>  $diFile  [bahan id => baris pertama] (diperbarui)
     */
    private function periksaTambah(int $no, array $isi, ?BahanBaku $b, string $namaBahan, array $data, array &$diFile): array
    {
        $mentah = $this->bacaTambah($isi);
        $jumlah = is_numeric($mentah) ? round((float) $mentah, 3) : null;
        $data['jumlah'] = $jumlah;

        if (blank($mentah) || $jumlah === 0.0) {
            return ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ['Tidak ada yang ditambahkan.'], 'catatan' => []];
        }

        $pesan = [];
        $catatan = [];

        if (! $b) {
            $pesan[] = $namaBahan === '' ? 'Nama bahan wajib diisi.' : "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
        } elseif (! $b->aktif) {
            $pesan[] = "Bahan {$b->nama} sedang nonaktif.";
        } elseif (isset($diFile[$b->id])) {
            $pesan[] = 'Bahan sama dengan baris '.$diFile[$b->id].'.';
        }

        if ($b) {
            $diFile[$b->id] ??= $no;
        }

        if ($b && $pesan === []) {
            [$jumlah, $pesanSatuan, $catatan] = $this->keSatuanBeli($b, $jumlah, (string) ($isi['satuan'] ?? ''));
            $pesan = [...$pesan, ...$pesanSatuan];
            $data['jumlah'] = is_numeric($jumlah) ? round((float) $jumlah, 3) : null;
        }

        if (! is_numeric($jumlah) || $jumlah <= 0 || $jumlah > BarangMasukRequest::MAKS_QTY) {
            $pesan[] = 'Jumlah tambah harus angka lebih dari 0, mis. 7,5. Untuk mengurangi stok, pilih Ganti atau pakai ikon pensil.';
        }

        if (mb_strlen((string) $data['keterangan']) > TambahStokRequest::MAKS_KETERANGAN) {
            $pesan[] = 'Keterangan maksimal '.TambahStokRequest::MAKS_KETERANGAN.' karakter.';
        }

        if ($pesan === []) {
            $data['stok_baru'] = round((float) $data['stok_lama'] + (float) $data['jumlah'], 3);
        }

        return [
            'baris'   => $no,
            'status'  => $pesan === [] ? self::STATUS_SIAP : self::STATUS_SALAH,
            'data'    => $data,
            'pesan'   => $pesan,
            'catatan' => $pesan === [] ? $catatan : [],
        ];
    }

    /**
     * Stok baru satu baris dan stok saat file diunduh (untuk dibandingkan dengan stok sekarang).
     *
     * @return array{0: mixed, 1: ?float, 2: list<string>, 3: ?string} [stok baru, stok saat diunduh, catatan, alasan bila tidak ada stok baru]
     */
    private function bacaStok(array $isi): array
    {
        $unduh = is_numeric($isi['stok_unduh'] ?? null) ? (float) $isi['stok_unduh'] : null;
        $sistem = is_numeric($isi['stok_sistem'] ?? null) ? (float) $isi['stok_sistem'] : null;

        if (filled($isi['stok_sebenarnya'] ?? null)) {
            return [$isi['stok_sebenarnya'], $unduh ?? $sistem, [], null];
        }

        if (filled($isi['stok'] ?? null)) {
            return [$isi['stok'], $unduh, [], null];
        }

        if ($sistem !== null && $unduh !== null && abs($sistem - $unduh) > self::TOLERANSI) {
            return [$sistem, $unduh, ['Stok baru diambil dari kolom Stok Sistem yang diubah.'], null];
        }

        if ($sistem !== null && $unduh === null && filled($isi['bahan'] ?? null)) {
            $b = BahanBaku::query()->whereRaw('LOWER(nama) = ?', [mb_strtolower(Str::squish((string) $isi['bahan']))])->first();
            $sekarang = $b ? $b->keSatuanBeli((float) DB::table('stok_saldo')->where('cabang_id', $this->lokasiDipakai()->id)->where('bahan_baku_id', $b->id)->value('saldo')) : null;

            if ($sekarang !== null && abs($sistem - $sekarang) > self::TOLERANSI) {
                return [null, null, [], 'File ini format lama dan angkanya berbeda dengan stok sekarang ('.Angka::tampil($sekarang).'). Unduh ulang file dari halaman Stok, lalu ubah angka di kolom Stok.'];
            }
        }

        return [null, null, [], 'Stok sebenarnya tidak diisi.'];
    }

    /**
     * Stok dari file ke satuan beli: satuan kosong/sama dengan satuan beli dibiarkan, satuan dasar dikonversi.
     *
     * @return array{0: mixed, 1: list<string>, 2: list<string>} [stok satuan beli, pesan salah, catatan]
     */
    private function keSatuanBeli(BahanBaku $b, mixed $stok, string $satuan): array
    {
        $satuan = mb_strtolower(Str::squish($satuan));

        if ($satuan === '' || $satuan === mb_strtolower($b->satuanBeli->nama) || ! is_numeric($stok)) {
            return [$stok, [], []];
        }

        if ($satuan === mb_strtolower($b->satuanDasar->nama)) {
            $beli = $b->keSatuanBeli($stok);

            return [$beli, [], [Angka::tampil($stok).' '.$b->satuanDasar->nama.' = '.Angka::tampil($beli).' '.$b->satuanBeli->nama]];
        }

        return [$stok, ["Satuan {$satuan} bukan satuan {$b->nama}. Pakai {$b->satuanBeli->nama}."], []];
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        $lokasi = $this->lokasiDipakai();

        $hasil = DB::transaction(function () use ($mentah, $oleh, $namaFile, $lokasi) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP)->values();
            $bahan = BahanBaku::query()->whereKey($siap->pluck('data.bahan_baku_id')->all())->get()->keyBy('id');
            $ketBawaan = Str::limit('Pembaruan stok lewat Excel'.($namaFile ? ' ('.$namaFile.')' : ''), TambahStokRequest::MAKS_KETERANGAN, '');

            foreach ($siap as $r) {
                if ($this->tambah()) {
                    $this->tambahStok->tambah($oleh, $lokasi, $bahan[$r['data']['bahan_baku_id']], (float) $r['data']['jumlah'],
                        $r['data']['keterangan'] ?? Str::limit('Tambah stok lewat Excel'.($namaFile ? ' ('.$namaFile.')' : ''), TambahStokRequest::MAKS_KETERANGAN, ''), beriTahu: false);

                    continue;
                }

                $this->penyesuaian->sesuaikan(
                    $oleh, $lokasi, $bahan[$r['data']['bahan_baku_id']], $r['data']['stok_baru'], $r['data']['stok_lama_dasar'],
                    $r['data']['keterangan'] ?? $ketBawaan, beriTahu: false,
                );
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return [
                'disimpan' => $siap->count(),
                'dilewati' => $hasil->count() - $siap->count(),
                'catatan'  => [],
            ];
        });

        if ($hasil['disimpan'] > 0 && ! $oleh->isAdminUtama()) {
            $this->notifikasi->keAdminUtama(
                ($this->tambah() ? 'Stok ditambah lewat Excel: ' : 'Stok diperbarui lewat Excel: ').$lokasi->nama,
                $hasil['disimpan'].' bahan · '.$oleh->nama,
                route('admin-utama.stok.mutasi', ['lokasi' => $lokasi->id, 'tampil' => 'hari'], false)
            );
        }

        return $hasil;
    }

    public function ringkasan(array $data): array
    {
        if ($data['stok_lama'] === null || $data['stok_baru'] === null) {
            return [$data['bahan'] !== '' ? $data['bahan'] : '(tanpa nama bahan)', ''];
        }

        $selisih = round($data['stok_baru'] - $data['stok_lama'], 3);

        return [
            $data['bahan'],
            collect([
                Angka::tampil($data['stok_lama']).' → '.Angka::tampil($data['stok_baru']).' '.$data['satuan']
                    .($selisih != 0.0 ? ' ('.($selisih > 0 ? '+' : '').Angka::tampil($selisih).')' : ''),
            ])->filter()->implode(' · '),
        ];
    }

    private function lokasiDipakai(): Cabang
    {
        return $this->lokasi ?? throw new LogicException('Lokasi pembaruan stok belum dipilih.');
    }
}
