<?php

namespace App\Services\Impor;

use App\Http\Requests\TambahStokRequest;
use App\Models\Cabang;
use App\Models\Menu;
use App\Models\User;
use App\Support\ContohTemplat;
use App\Support\LembarExcel;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Impor Sekaligus: satu file Excel berisi beberapa lembar (Lokasi, Satuan, Bahan, Menu, Resep, Stok Minimum, Stok).
 * Lembar dikenali dari namanya (atau judul kolomnya), lembar yang tidak ada atau kosong dilewati, dan setiap lembar
 * diproses dengan impor per menu yang sudah ada, berurutan seperti di LEMBAR, sehingga data baru di lembar sebelumnya
 * (mis. bahan baru) langsung dikenali di lembar sesudahnya.
 *
 * - Pemeriksaan (pratinjau) menjalankan semua lembar di dalam transaksi yang lalu dibatalkan, jadi hasilnya sama
 *   dengan saat disimpan tanpa mengubah data.
 * - Data yang sudah ada dilewati (lokasi, bahan, menu), resep yang berbeda menjadi versi baru, stok minimum ditimpa.
 * - Bahan tanpa satuan memakai pcs dan tanpa kategori memakai Lainnya (bisa diubah nanti di menu Bahan Baku).
 * - Lembar Stok wajib berkolom Lokasi (kode/nama, "Semua", atau "Semua Cabang"); cara simpan Ganti (bawaan) atau Tambah.
 */
class ImporSekaligusService
{
    public const MODE_GANTI = ImporPenyesuaianStokService::MODE_SAMAKAN;

    public const MODE_TAMBAH = ImporPenyesuaianStokService::MODE_TAMBAH;

    public const SATUAN_BAWAAN = 'pcs';

    public const KATEGORI_BAWAAN = 'Lainnya';

    /** Jenis lembar sesuai urutan proses: [label, kelas impor, nama lembar yang dikenali (dirapatkan)]. */
    public const LEMBAR = [
        'lokasi'       => ['Lokasi', ImporLokasiService::class, ['lokasi', 'location', 'locations', 'outlet', 'cabang', 'gudang']],
        'satuan'       => ['Satuan', ImporSatuanService::class, ['satuan', 'unit', 'units', 'uom']],
        'bahan'        => ['Bahan', ImporBahanService::class, ['bahan', 'bahanbaku', 'ingredient', 'ingredients', 'material', 'barang', 'item', 'items']],
        'menu'         => ['Menu', ImporMenuService::class, ['menu', 'daftarmenu', 'produk', 'product', 'products']],
        'resep'        => ['Resep', ImporResepService::class, ['resep', 'recipe', 'recipes', 'menuresep', 'menudanresep', 'resepmenu']],
        'stok_minimum' => ['Stok Minimum', ImporStokMinimumService::class, ['stokminimum', 'minimum', 'stokminimal', 'minstok', 'minimumstok', 'minimumstock']],
        'stok'         => ['Stok', ImporPenyesuaianStokService::class, ['stok', 'stock', 'stokawal', 'persediaan', 'inventory', 'stokgudang']],
    ];

    /** Keterangan kolom yang berbeda di Impor Sekaligus karena boleh kosong (diisi bawaan). */
    private const KET_BAWAAN = [
        'bahan' => [
            'kategori'     => 'Boleh kosong (dipakai Lainnya). Yang belum ada dibuat otomatis.',
            'satuan'       => 'Boleh kosong (dipakai pcs). Satuan stok, mis. kg, liter, pcs, botol. Untuk kg dan liter, resep ditulis dalam gram dan ml.',
        ],
        'menu'  => ['kategori_menu' => 'Boleh kosong (dipakai Lainnya). Yang belum ada dibuat otomatis.'],
        'resep' => ['kategori_menu' => 'Untuk menu baru. Boleh kosong (dipakai Lainnya).'],
    ];

    /** Lembar di templat, urut seperti di file. */
    private const LEMBAR_TEMPLAT = ['lokasi', 'bahan', 'menu', 'resep', 'stok_minimum', 'stok'];

    private string $mode = self::MODE_GANTI;

    /** @return array<string, array{0: string}> */
    public function pilihanMode(): array
    {
        return [self::MODE_GANTI => ['Ganti'], self::MODE_TAMBAH => ['Tambah']];
    }

    public function pakaiMode(mixed $mode): static
    {
        $this->mode = is_string($mode) && array_key_exists($mode, $this->pilihanMode()) ? $mode : self::MODE_GANTI;

        return $this;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Baca semua lembar yang dikenali.
     *
     * @return array{lembar: array<string, array{nama: string, mentah: array<int, array<string, mixed>>}>, diabaikan: list<string>}
     *
     * @throws DomainException bila tidak ada satu pun lembar yang dikenali
     */
    public function baca(UploadedFile $file): array
    {
        $hasil = [];
        $diabaikan = [];
        $belum = [];

        foreach (LembarExcel::daftarLembar($file) as $nama) {
            $blok = $this->cariBlok($file, $nama);

            if (count($blok) > 1) {
                foreach ($blok as [$jenisBlok, $rentang]) {
                    if (isset($hasil[$jenisBlok]) && $hasil[$jenisBlok]['nama'] === $nama) {
                        $hasil[$jenisBlok]['mentah'] += $this->bacaLembar($file, $jenisBlok, $nama, $rentang);
                    } elseif (! isset($hasil[$jenisBlok])) {
                        $hasil[$jenisBlok] = ['nama' => $nama, 'mentah' => $this->bacaLembar($file, $jenisBlok, $nama, $rentang)];
                    } else {
                        $diabaikan[] = $nama.' ('.self::LEMBAR[$jenisBlok][0].')';
                    }
                }

                continue;
            }

            $jenis = $this->jenisDariNama($nama);

            if ($jenis !== null && ! isset($hasil[$jenis])) {
                $hasil[$jenis] = ['nama' => $nama, 'mentah' => $this->bacaLembar($file, $jenis, $nama)];
            } elseif (! in_array(LembarExcel::rapatkanJudul($nama), ['petunjuk', 'panduan', 'bacadulu', 'readme', 'catatan'], true)) {
                $belum[] = $nama;
            }
        }

        foreach ($belum as $nama) {
            $jenis = $this->jenisDariJudulKolom($file, $nama, array_keys($hasil));

            if ($jenis !== null) {
                $hasil[$jenis] = ['nama' => $nama, 'mentah' => $this->bacaLembar($file, $jenis, $nama)];
            } else {
                $diabaikan[] = $nama;
            }
        }

        $hasil = array_filter($hasil, fn (array $l) => $l['mentah'] !== []);

        if ($hasil === []) {
            throw new DomainException('Tidak ada lembar yang dikenali. Beri nama lembar Lokasi, Bahan, Menu, Resep, Stok Minimum, atau Stok.');
        }

        uksort($hasil, fn ($a, $b) => array_search($a, array_keys(self::LEMBAR), true) <=> array_search($b, array_keys(self::LEMBAR), true));

        return ['lembar' => $hasil, 'diabaikan' => $diabaikan];
    }

    /**
     * Periksa semua lembar seolah disimpan (transaksi dibatalkan di akhir).
     *
     * @param  array<string, array{nama: string, mentah: array}>  $lembar
     * @param  array<string, string>  $keputusan
     * @return array<string, array{label: string, nama: string, baris: list<array>, layanan: ImporExcel, galat: ?string}>
     */
    public function periksa(array $lembar, array $keputusan, User $oleh): array
    {
        DB::beginTransaction();

        try {
            return $this->jalankan($lembar, $keputusan, $oleh, null, true);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Simpan semua lembar dalam satu transaksi.
     *
     * @return array<string, array{label: string, disimpan: int, dilewati: int, galat: ?string}>
     */
    public function simpan(array $lembar, array $keputusan, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(fn () => collect($this->jalankan($lembar, $keputusan, $oleh, $namaFile ?? 'impor sekaligus'))
            ->map(fn (array $l) => ['label' => $l['label'], 'disimpan' => $l['disimpan'], 'dilewati' => $l['dilewati'], 'galat' => $l['galat']])
            ->all());
    }

    /** Pesan setelah simpan, mis. "Tersimpan: Bahan 45, Resep 17 menu, Stok 120. 12 baris dilewati." */
    public function pesanSimpan(array $hasil): string
    {
        $simpan = collect($hasil)->filter(fn (array $h) => $h['disimpan'] > 0)
            ->map(fn (array $h, string $jenis) => $h['label'].' '.$h['disimpan'].($jenis === 'resep' ? ' menu' : ''))
            ->implode(', ');
        $dilewati = collect($hasil)->sum('dilewati');

        return ($simpan !== '' ? 'Tersimpan: '.$simpan.'.' : 'Tidak ada data baru.').($dilewati > 0 ? ' '.$dilewati.' baris dilewati.' : '');
    }

    public function templat(): Spreadsheet
    {
        $buku = new Spreadsheet();
        $buku->removeSheetByIndex(0);

        $judul = [...array_map(fn (string $jenis) => self::LEMBAR[$jenis][0], self::LEMBAR_TEMPLAT), 'Petunjuk'];
        $sumber = [...array_map(fn (string $jenis) => $jenis === 'stok' ? $this->templatStok() : $this->layanan($jenis)->templat(), self::LEMBAR_TEMPLAT), $this->petunjuk()];

        foreach ($sumber as $i => $isi) {
            $lembar = $isi->getSheet(0);
            $lembar->setTitle('L'.$i.'-'.Str::random(6));
            $baru = $buku->addExternalSheet($lembar);
            $baru->setTitle($judul[$i]);

            foreach ($baru->getTableCollection() as $t => $tabel) {
                $tabel->setName('Tabel_'.Str::slug($judul[$i], '_').'_'.($t + 1));
            }
        }

        $buku->setActiveSheetIndex(0);

        return $buku;
    }

    /** Kolom tiap lembar untuk halaman unggah: [label => [judul kolom => keterangan]]. */
    public function kolom(): array
    {
        return collect(self::LEMBAR_TEMPLAT)->mapWithKeys(fn (string $jenis) => [
            self::LEMBAR[$jenis][0] => collect($jenis === 'stok' ? self::kolomStok() : $this->layanan($jenis)->kolom())
                ->map(fn (array $t, string $kolom) => self::KET_BAWAAN[$jenis][$kolom] ?? $t['ket'])->all(),
        ])->all();
    }

    /**
     * @return array<string, array{label: string, nama: string, baris: list<array>, layanan: ImporExcel, galat: ?string, disimpan: int, dilewati: int}>
     */
    private function jalankan(array $lembar, array $keputusan, User $oleh, ?string $namaFile, bool $simulasi = false): array
    {
        $hasil = [];

        foreach (array_keys(self::LEMBAR) as $jenis) {
            if (! isset($lembar[$jenis])) {
                continue;
            }

            $mentah = $lembar[$jenis]['mentah'];
            $label = self::LEMBAR[$jenis][0];

            if ($jenis === 'stok') {
                $hasil[$jenis] = ['label' => $label, 'nama' => $lembar[$jenis]['nama']] + $this->jalankanStok($mentah, $keputusan, $oleh, $namaFile, $simulasi);

                continue;
            }

            $layanan = $this->layanan($jenis);
            $catatan = [];

            if ($jenis === 'bahan') {
                [$mentah, $catatan] = $this->isiBawaanBahan($mentah);
            } elseif ($jenis === 'menu' || $jenis === 'resep') {
                [$mentah, $catatan] = $this->isiBawaanKategoriMenu($mentah, $jenis === 'menu' ? 'nama' : 'menu');
            }

            $baris = array_map(function (array $r) use ($catatan) {
                $r['catatan'] = [...($catatan[$r['baris']] ?? []), ...$r['catatan']];

                return $r;
            }, $layanan->periksaBersih($mentah, $keputusan));

            $pakai = $simulasi ? $this->anggapDiterima($baris, $keputusan) : $keputusan;
            [$disimpan, $dilewati, $galat] = $this->simpanLembar(fn () => $layanan->simpanBersih($mentah, $pakai, $oleh, $namaFile), count($baris));

            $hasil[$jenis] = ['label' => $label, 'nama' => $lembar[$jenis]['nama'], 'baris' => $baris, 'layanan' => $layanan,
                'galat' => $galat, 'disimpan' => $disimpan, 'dilewati' => $dilewati];
        }

        return $hasil;
    }

    /**
     * Lembar Stok: baris dikelompokkan per lokasi, "Semua"/"Semua Cabang" dipecah ke tiap lokasi (baris yang menulis
     * lokasi langsung lebih diutamakan), lalu tiap kelompok diproses seperti Perbarui Lewat Excel lokasi itu.
     */
    private function jalankanStok(array $mentah, array $keputusan, User $oleh, ?string $namaFile, bool $simulasi = false): array
    {
        $lokasi = Cabang::query()->get();
        $kunciBahan = fn (array $isi) => mb_strtolower(Str::squish((string) ($isi['bahan'] ?? '')));
        $baris = [];
        $kelompok = [];
        $langsung = [];
        $lainnya = 0;

        foreach ($mentah as $isi) {
            $teks = Str::squish((string) ($isi['lokasi'] ?? ''));
            if (LokasiImpor::jenisSemua($teks) === null && ($l = LokasiImpor::cari($lokasi, $teks))) {
                $langsung[$l->id.'|'.$kunciBahan($isi)] = true;
            }
        }

        foreach ($mentah as $no => $isi) {
            $teks = Str::squish((string) ($isi['lokasi'] ?? ''));
            $semua = LokasiImpor::jenisSemua($teks);
            $sasaran = $semua
                ? LokasiImpor::sasaran($semua)->reject(fn (Cabang $l) => isset($langsung[$l->id.'|'.$kunciBahan($isi)]))
                : collect(array_filter([LokasiImpor::cari($lokasi, $teks)]));

            $salah = match (true) {
                $teks === '' => 'Lokasi wajib diisi (kode lokasi, Semua, atau Semua Cabang).',
                ! $semua && $sasaran->isEmpty() => "Lokasi {$teks} tidak dikenal.",
                ! $semua && ! $sasaran->first()->aktif => $sasaran->first()->nama.' sedang nonaktif.',
                default => null,
            };

            if ($salah !== null || $sasaran->isEmpty()) {
                $lainnya++;
                $baris[] = ['baris' => $no, 'status' => $salah !== null ? ImporExcel::STATUS_SALAH : ImporExcel::STATUS_DILEWATI,
                    'data' => [], 'pesan' => [$salah ?? 'Semua lokasinya sudah diisi di baris lain.'], 'catatan' => [],
                    'betul' => [], 'tebakan' => [], 'tunggu' => [], 'ringkas' => [Str::squish((string) ($isi['bahan'] ?? '')) ?: '(tanpa nama bahan)', $teks]];

                continue;
            }

            foreach ($sasaran as $l) {
                $kelompok[$l->id][$no] = ['lokasi' => $l->kode] + $isi;
            }
        }

        $disimpan = 0;
        $dilewati = 0;
        $galat = [];
        $layananPertama = null;

        foreach ($kelompok as $lokasiId => $isiLokasi) {
            $l = $lokasi->firstWhere('id', $lokasiId);
            $layanan = app(ImporPenyesuaianStokService::class)->diLokasi($l)->pakaiMode($this->mode);
            $layananPertama ??= $layanan;

            $periksa = $layanan->periksaBersih($isiLokasi, $keputusan);

            foreach ($periksa as $r) {
                [$judul, $ket] = $r['ringkas'] ?? $layanan->ringkasan($r['data']);

                if ($l->opname_aktif && $r['status'] === ImporExcel::STATUS_SIAP) {
                    $r['status'] = ImporExcel::STATUS_SALAH;
                    $r['pesan'] = [$l->nama.' sedang stock opname. Selesaikan opname dulu.'];
                }

                $baris[] = $r + ['ringkas' => [$judul, collect([$l->nama, $ket])->filter()->implode(' · ')]];
            }

            if ($l->opname_aktif) {
                $dilewati += count($isiLokasi);

                continue;
            }

            $pakai = $simulasi ? $this->anggapDiterima($periksa, $keputusan) : $keputusan;
            [$s, $d, $g] = $this->simpanLembar(fn () => $layanan->simpanBersih($isiLokasi, $pakai, $oleh, $namaFile), count($isiLokasi));
            $disimpan += $s;
            $dilewati += $d;
            if ($g !== null) {
                $galat[] = $l->nama.': '.$g;
            }
        }

        $dilewati += $lainnya;
        usort($baris, fn ($a, $b) => $a['baris'] <=> $b['baris']);

        return [
            'baris'    => $baris,
            'layanan'  => $layananPertama ?? app(ImporPenyesuaianStokService::class)->pakaiMode($this->mode),
            'galat'    => $galat === [] ? null : implode(' ', $galat),
            'disimpan' => $disimpan,
            'dilewati' => $dilewati,
        ];
    }

    /**
     * Saat pratinjau, pertanyaan "Maksudnya …?" yang belum dijawab dianggap Ya supaya lembar berikutnya bisa merujuk
     * data baris itu (mis. bahan yang kategorinya masih ditanyakan tetap dikenali di lembar Resep dan Stok).
     */
    private function anggapDiterima(array $baris, array $keputusan): array
    {
        return $keputusan + collect($baris)->flatMap(fn (array $r) => $r['tebakan'])->whereNull('jawab')
            ->mapWithKeys(fn (array $t) => [$t['kunci'] => PembersihImpor::TERIMA])->all();
    }

    /** @return array{0: int, 1: int, 2: ?string} [disimpan, dilewati, galat] */
    private function simpanLembar(callable $simpan, int $jumlahBaris): array
    {
        try {
            $h = $simpan();

            return [$h['disimpan'], $h['dilewati'], null];
        } catch (DomainException $e) {
            return [0, $jumlahBaris, $e->getMessage()];
        }
    }

    /**
     * Bahan tanpa satuan memakai pcs, tanpa kategori memakai Lainnya.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, list<string>>}
     */
    private function isiBawaanBahan(array $mentah): array
    {
        $catatan = [];

        foreach ($mentah as $no => $isi) {
            if (blank($isi['nama'] ?? null)) {
                continue;
            }

            if (blank($isi['satuan'] ?? null) && blank($isi['satuan_dasar'] ?? null)) {
                $mentah[$no]['satuan'] = self::SATUAN_BAWAAN;
                $catatan[$no][] = 'Satuan kosong, dipakai '.self::SATUAN_BAWAAN.'. Bisa diubah di menu Bahan Baku.';
            }

            if (blank($isi['kategori'] ?? null)) {
                $mentah[$no]['kategori'] = self::KATEGORI_BAWAAN;
                $catatan[$no][] = 'Kategori kosong, dipakai '.self::KATEGORI_BAWAAN.'.';
            }
        }

        return [$mentah, $catatan];
    }

    /**
     * Menu baru tanpa kategori menu memakai Lainnya. Di lembar Resep hanya untuk menu yang belum ada dan tidak satu
     * pun barisnya berkategori.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, list<string>>}
     */
    private function isiBawaanKategoriMenu(array $mentah, string $kolomMenu): array
    {
        $ada = Menu::query()->pluck('nama')->map(fn ($n) => mb_strtolower($n))->flip();
        $kelompok = collect($mentah)->groupBy(fn ($isi) => mb_strtolower(Str::squish((string) ($isi[$kolomMenu] ?? ''))), true);
        $catatan = [];

        foreach ($kelompok as $kunci => $daftar) {
            if ($kunci === '' || isset($ada[$kunci]) || $daftar->contains(fn ($isi) => filled($isi['kategori_menu'] ?? null))) {
                continue;
            }

            foreach ($daftar->keys() as $i => $no) {
                $mentah[$no]['kategori_menu'] = self::KATEGORI_BAWAAN;
                if ($i === 0) {
                    $catatan[$no][] = 'Kategori menu kosong, dipakai '.self::KATEGORI_BAWAAN.'.';
                }
            }
        }

        return [$mentah, $catatan];
    }

    private function bacaLembar(UploadedFile $file, string $jenis, string $nama, ?array $rentang = null): array
    {
        try {
            return $this->layananBaca($jenis)->diLembar($nama)->diRentang($rentang)->bacaFile($file);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'File tidak berisi data.') {
                return [];
            }

            throw new DomainException('Lembar '.$nama.': '.$e->getMessage());
        }
    }

    /** Pembaca lembar: bahan dan menu cukup berkolom nama, resep cukup berkolom menu (sisanya boleh bolong). */
    private function layananBaca(string $jenis): ImporExcel
    {
        return match ($jenis) {
            'bahan', 'menu' => $this->layanan($jenis)->wajibBaca(['nama']),
            'resep'         => $this->layanan($jenis)->wajibBaca(['menu']),
            default         => $this->layanan($jenis),
        };
    }

    private function layanan(string $jenis): ImporExcel
    {
        return app(self::LEMBAR[$jenis][1]);
    }

    /**
     * Tabel-tabel dalam satu lembar (semua data ditumpuk dalam satu lembar). Baris judul tabel dikenali dari judul
     * kolomnya; baris berisi satu tulisan nama jenis (mis. "BAHAN") di atasnya dipakai sebagai petunjuk dan tidak ikut
     * dibaca. Bila judul cocok untuk beberapa jenis, dipilih yang judul kolomnya paling banyak dikenali, lalu yang
     * kolomnya paling sedikit (mis. tabel berkolom Nama saja = Satuan).
     *
     * @return list<array{0: string, 1: array{0: int, 1: int}}> [jenis, [baris awal, baris akhir]] (indeks mulai 0)
     */
    private function cariBlok(UploadedFile $file, string $nama): array
    {
        $layanan = collect(array_keys(self::LEMBAR))->mapWithKeys(fn (string $j) => [$j => $this->layananBaca($j)]);
        $kepala = [];
        $petunjuk = null;
        $barisPetunjuk = null;

        foreach (LembarExcel::barisLembar($file, $nama) as $i => $baris) {
            $isi = array_values(array_filter($baris, fn ($v) => trim((string) $v) !== ''));

            if ($isi === []) {
                continue;
            }

            if (count($isi) === 1 && ($j = $this->jenisDariNama((string) $isi[0])) !== null) {
                [$petunjuk, $barisPetunjuk] = [$j, $i];

                continue;
            }

            $skor = $layanan->map(fn (ImporExcel $l) => $l->skorJudul($baris))->filter();

            if ($skor->isNotEmpty()) {
                $jenis = $petunjuk !== null && $skor->has($petunjuk)
                    ? $petunjuk
                    : $skor->sortBy(fn (int $s, string $j) => [-$s, count($layanan[$j]->kolom())])->keys()->first();
                $kepala[] = [$jenis, $i, $barisPetunjuk ?? $i];
            }

            [$petunjuk, $barisPetunjuk] = [null, null];
        }

        return collect($kepala)->values()->map(fn (array $k, int $n) => [
            $k[0],
            [$k[1], isset($kepala[$n + 1]) ? $kepala[$n + 1][2] - 1 : PHP_INT_MAX],
        ])->all();
    }

    private function jenisDariNama(string $nama): ?string
    {
        $rapat = LembarExcel::rapatkanJudul($nama);

        foreach (self::LEMBAR as $jenis => [, , $dikenal]) {
            if (in_array($rapat, $dikenal, true)) {
                return $jenis;
            }
        }

        return null;
    }

    /** Lembar bernama lain: dikenali dari judul kolomnya (jenis yang lebih khusus dicoba lebih dulu). */
    private function jenisDariJudulKolom(UploadedFile $file, string $nama, array $sudah): ?string
    {
        foreach (['stok_minimum', 'resep', 'stok', 'bahan', 'menu', 'lokasi'] as $jenis) {
            if (! in_array($jenis, $sudah, true) && $this->layanan($jenis)->punyaJudul($file, $nama)) {
                return $jenis;
            }
        }

        return null;
    }

    /** @return array<string, array{lebar: int, ket: string, contoh?: mixed}> */
    private static function kolomStok(): array
    {
        return [
            'lokasi'     => ['lebar' => 16, 'ket' => 'Wajib. Kode lokasi, mis. GP. "Semua" = semua lokasi, "Semua Cabang" = semua cabang.', 'contoh' => 'GP'],
            'bahan'      => ['lebar' => 28, 'ket' => 'Wajib. Nama bahan seperti di lembar Bahan atau daftar bahan.', 'contoh' => 'Beras Jepang'],
            'satuan'     => ['lebar' => 10, 'ket' => 'Satuan bahan, mis. kg. Bila diisi gram atau ml, angkanya dikonversi otomatis.', 'contoh' => 'kg'],
            'stok'       => ['lebar' => 12, 'ket' => 'Ganti: stok menjadi angka ini. Tambah: angka ini ditambahkan ke stok sekarang.', 'contoh' => 10],
            'keterangan' => ['lebar' => 24, 'ket' => 'Boleh kosong, maksimal '.TambahStokRequest::MAKS_KETERANGAN.' karakter.', 'contoh' => null],
        ];
    }

    private function templatStok(): Spreadsheet
    {
        $kode = ContohTemplat::kodeLokasi();
        $isi = ContohTemplat::bahan()->values()->map(fn (array $b, int $i) => [
            'lokasi' => $kode, 'bahan' => $b['nama'], 'satuan' => $b['satuan_beli'], 'stok' => ContohTemplat::angka($i), 'keterangan' => null,
        ])->all();

        return LembarExcel::templat('Stok', self::kolomStok(), $isi);
    }

    private function petunjuk(): Spreadsheet
    {
        $isi = [
            ['Lokasi', 'Lokasi baru. Kode yang sudah ada dilewati. Jenis kosong = Cabang; Gudang Pusat hanya boleh satu.'],
            ['Bahan', 'Bahan baru. Nama yang sudah ada dilewati. Satuan kosong = pcs, kategori kosong = Lainnya. Satuan dan kategori baru dibuat otomatis.'],
            ['Menu', 'Menu baru. Nama yang sudah ada dilewati. Kategori menu kosong = Lainnya. Kategori menu baru dibuat otomatis.'],
            ['Resep', 'Satu baris satu bahan dalam resep satu menu. Menu baru ikut dibuat. Bahan kosong = menu tanpa resep. Resep yang berbeda menjadi versi baru.'],
            ['Stok Minimum', 'Per lokasi. "Semua" atau "Semua Cabang" di kolom Lokasi berlaku untuk banyak lokasi. Minimum yang sudah ada ditimpa.'],
            ['Stok', 'Per lokasi, wajib berkolom Lokasi. Pilih Ganti (stok menjadi angka di file) atau Tambah (ditambahkan) saat mengunggah.'],
            ['', 'Lembar yang tidak dipakai boleh dihapus atau dikosongkan. Semua tabel juga boleh ditumpuk dalam satu lembar, tiap tabel diawali baris judul kolomnya. Kolom tambahan diabaikan. Baris salah tidak disimpan; baris lain tetap.'],
        ];

        return LembarExcel::templat('Panduan', [
            'lembar'     => ['lebar' => 16, 'ket' => ''],
            'cara_isi'   => ['lebar' => 110, 'ket' => ''],
        ], array_map(fn ($r) => ['lembar' => $r[0], 'cara_isi' => $r[1]], $isi));
    }
}
