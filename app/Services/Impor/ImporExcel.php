<?php

namespace App\Services\Impor;

use App\Support\Angka;
use App\Support\JumlahPintar;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\User;
use App\Services\LogPerubahanService;
use App\Support\LembarExcel;
use App\Support\Teks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Dasar impor & ekspor Excel (gaya Majoo): templat kosong, ekspor dengan kolom yang sama, baca file,
 * bersihkan isinya (PembersihImpor), periksa setiap baris (Siap / Perlu dicek / Dilewati / Salah isi),
 * lalu simpan yang siap. Pembersihan dan pemeriksaan diulang saat simpan dengan jawaban tebakan dari pengguna.
 */
abstract class ImporExcel
{
    public const STATUS_SIAP = 'siap';
    public const STATUS_DILEWATI = 'dilewati';
    public const STATUS_SALAH = 'salah';
    public const STATUS_CEK = 'cek';

    /** Jenis kolom bawaan untuk pembersih, dari kunci kolom (lihat PembersihImpor). */
    private const JENIS_BAWAAN = [
        'bahan'               => KamusImpor::BAHAN,
        'menu'                => KamusImpor::MENU,
        'lokasi'              => KamusImpor::LOKASI,
        'kategori'            => KamusImpor::KATEGORI,
        'kategori_menu'       => KamusImpor::KATEGORI_MENU,
        'satuan'              => KamusImpor::SATUAN,
        'satuan_dasar'        => KamusImpor::SATUAN,
        'satuan_beli'         => KamusImpor::SATUAN,
        'isi_per_satuan_beli' => 'angka',
        'toleransi_persen'    => 'angka',
        'takaran'             => 'angka',
        'minimum'             => 'angka',
        'porsi'               => 'angka',
        'jumlah'              => 'angka',
        'stok_sistem'         => 'angka',
        'stok_sebenarnya'     => 'angka',
        'stok'                => 'angka',
        'hitung_fisik'        => 'angka',
    ];

    public const MAKS_BARIS = 2000;

    /** Baris contoh templat dilewati saat file terakhir dibaca. */
    protected bool $contohDilewati = false;

    /** Cara simpan yang dipilih saat unggah (kunci pilihanMode()), null bila impor ini tidak punya pilihan. */
    protected ?string $mode = null;

    public function __construct(protected LogPerubahanService $log)
    {
    }

    /**
     * Pilihan cara simpan yang ditampilkan saat unggah, mis. Tambah / Ganti. Kunci pertama = bawaan.
     *
     * @return array<string, array{0: string}> [kunci => [label]]
     */
    public function pilihanMode(): array
    {
        return [];
    }

    /** Pakai cara simpan dari form unggah; nilai yang tidak dikenal memakai pilihan bawaan. */
    public function pakaiMode(mixed $mode): static
    {
        $pilihan = $this->pilihanMode();
        $this->mode = $pilihan === [] ? null : (is_string($mode) && isset($pilihan[$mode]) ? $mode : array_key_first($pilihan));

        return $this;
    }

    public function mode(): ?string
    {
        if ($this->mode === null && $this->pilihanMode() !== []) {
            $this->pakaiMode(null);
        }

        return $this->mode;
    }

    /** Nama data, mis. "Bahan baku". Dipakai untuk judul lembar, nama file, dan pesan. */
    abstract public function judul(): string;

    /**
     * Kolom templat: lebar, cara isi (ket), contoh (isi baris contoh di templat), info (hanya keterangan), tanggal.
     *
     * @return array<string, array{lebar: int, ket: string, contoh?: mixed, info?: bool, tanggal?: bool}> [kunci kolom => tampilan]
     */
    abstract public function kolom(): array;

    /** @return list<string|list<string>> kolom yang wajib ada di baris judul file; daftar di dalamnya = salah satu cukup */
    abstract protected function kolomWajib(): array;

    /**
     * Judul kolom lain yang dikenali saat membaca file, supaya file buatan sendiri atau hasil unduhan halaman lain
     * tetap terbaca (dibandingkan tanpa huruf besar-kecil, spasi, tanda baca, dan isi kurung).
     */
    private const SINONIM = [
        'nama'            => ['Nama Data', 'Name', 'Nama Bahan', 'Nama Menu', 'Nama Kategori', 'Nama Satuan'],
        'bahan'           => ['Nama', 'Name', 'Nama Bahan', 'Bahan Baku', 'Nama Bahan Baku', 'Nama Stok', 'Item', 'Nama Item', 'Item Name',
                              'Barang', 'Nama Barang', 'Produk', 'Nama Produk', 'Product', 'Product Name', 'Ingredient', 'Material'],
        'menu'            => ['Nama', 'Name', 'Nama Menu', 'Menu Item', 'Produk', 'Nama Produk', 'Product', 'Product Name', 'Item', 'Nama Item', 'Item Name'],
        'lokasi'          => ['Gudang', 'Cabang', 'Outlet', 'Toko', 'Store', 'Location', 'Kode Lokasi', 'Nama Lokasi'],
        'kategori'        => ['Kategori Bahan', 'Kelompok', 'Golongan', 'Category'],
        'kategori_menu'   => ['Kategori', 'Category'],
        'satuan'          => ['Unit', 'UOM', 'Sat', 'Satuan Beli', 'Unit of Measure'],
        'tanggal'         => ['Tgl', 'Date', 'Hari', 'Tanggal Masuk', 'Tanggal Terima', 'Tanggal Penjualan'],
        'porsi'           => ['Qty', 'Jumlah', 'Jumlah Porsi', 'Porsi Terjual', 'Terjual', 'Jumlah Terjual', 'Kuantitas', 'Quantity', 'Sold', 'Pcs'],
        'jumlah'          => ['Qty', 'Kuantitas', 'Quantity', 'Banyak', 'Banyaknya', 'Jumlah Masuk', 'Jumlah Diterima', 'Jumlah Barang'],
        'minimum'         => ['Stok Minimum', 'Min', 'Minimal', 'Stok Minimal', 'Minimum Stock'],
        'takaran'         => ['Jumlah', 'Qty', 'Takaran per Porsi'],
        'keterangan'      => ['Ket', 'Catatan', 'Note', 'Notes', 'Remarks'],
        'catatan'         => ['Keterangan', 'Ket', 'Note'],
        'stok_sebenarnya' => ['Stok Fisik', 'Stok Baru', 'Hasil Hitung', 'Jumlah Fisik', 'Stok Aktual', 'Stok Real', 'Fisik'],
        'stok_sistem'     => ['Stok Lama', 'Stok di Sistem'],
        'hitung_fisik'    => ['Fisik', 'Stok Fisik', 'Hasil Hitung', 'Jumlah Fisik', 'Qty Fisik', 'Stok Sebenarnya', 'Stok Aktual', 'Stok Real',
                              'Hitung', 'Jumlah Hitung', 'Stok', 'Stock', 'Qty', 'Jumlah', 'Sisa', 'Sisa Stok', 'Stok Akhir'],
        'isi_kemasan'     => ['Isi', 'Isi Kemasan', 'Isi per Kemasan', 'Isi per Item', 'Ukuran', 'Volume', 'Berat', 'Isi Satuan'],
        'stok'            => ['Qty', 'Jumlah', 'Kuantitas', 'Quantity', 'Stock', 'Sisa', 'Sisa Stok', 'Stok Akhir', 'Saldo', 'Jumlah Stok',
                              'Stok Sekarang', 'Stok Gudang', 'Persediaan', 'On Hand', 'Jumlah Barang', 'Banyaknya'],
    ];

    /** Kolom angka yang bisa dikenali dari isinya bila judulnya tidak dikenal. */
    private const KOLOM_ANGKA = ['stok', 'stok_sebenarnya', 'stok_sistem', 'hitung_fisik', 'porsi', 'jumlah', 'minimum', 'takaran'];

    /** @return list<list<mixed>> isi ekspor, urutan kolom sama dengan kolom() */
    abstract protected function barisEkspor(): array;

    /**
     * @param  array<int, array<string, mixed>>  $mentah
     * @return list<array{baris: int, status: string, data: array, pesan: list<string>, catatan: list<string>, ringkas?: array{0: string, 1: string}}>
     *         ringkas = judul & keterangan siap pakai (bila tidak ada, ringkasan(data) dipakai)
     */
    abstract public function periksa(array $mentah): array;

    /**
     * Simpan baris yang masih siap setelah diperiksa ulang.
     *
     * @param  array<int, array<string, mixed>>  $mentah
     * @return array{disimpan: int, dilewati: int, catatan: list<string>}
     */
    abstract public function simpan(array $mentah, User $oleh, ?string $namaFile): array;

    /**
     * Ringkasan satu baris di pratinjau.
     *
     * @return array{0: string, 1: string} [judul, keterangan]
     */
    abstract public function ringkasan(array $data): array;

    /**
     * Jenis tiap kolom untuk pembersih isian. Kolom tanggal dari tanda 'tanggal'; kolom keterangan ('info')
     * tidak dicocokkan dengan nama di sistem.
     *
     * @return array<string, string>
     */
    public function jenisKolom(): array
    {
        $tambahan = collect($this->kolomTambahan())->mapWithKeys(fn (string $k) => [$k => []]);

        return collect($this->kolom())->union($tambahan)->map(function (array $t, string $kunci) {
            $jenis = ($t['tanggal'] ?? false) ? 'tanggal' : (self::JENIS_BAWAAN[$kunci] ?? 'teks');

            return ($t['info'] ?? false) && isset(KamusImpor::LABEL[$jenis]) ? 'teks' : $jenis;
        })->all();
    }

    /**
     * Baris yang harus disimpan bersama (mis. semua bahan satu resep, semua menu satu catatan penjualan).
     * Bila satu baris masih menunggu jawaban tebakan, seluruh kelompoknya ikut menunggu. Null = berdiri sendiri.
     */
    protected function kunciKelompok(array $isi): ?string
    {
        return null;
    }

    /**
     * Baris yang pasti dilewati apa pun jawabannya (mis. porsi kosong atau 0), sehingga tebakan namanya tidak perlu
     * ditanyakan dan tidak menahan baris lain. Dipanggil dengan isi yang sudah dibersihkan.
     */
    protected function barisTanpaTanya(array $isi): bool
    {
        return false;
    }

    /** Pembersihan + buang tebakan pada baris yang tidak perlu ditanyakan. */
    private function bersihkan(array $mentah, array $keputusan): array
    {
        $bersih = (new PembersihImpor)->bersihkan($this->kaliIsiKemasan($mentah), $this->jenisKolom(), $keputusan);
        $bersih = $this->hitungJumlahPintar($bersih);

        foreach ($bersih['mentah'] as $no => $isi) {
            if ($this->barisTanpaTanya($isi)) {
                $bersih['info'][$no]['tebakan'] = [];
            }
        }

        return $bersih;
    }

    /**
     * Bersihkan lalu periksa. Setiap baris mendapat 'betul' (isian yang dibetulkan otomatis), 'tebakan', dan
     * 'tunggu' (kunci tebakan yang belum dijawab); baris dengan tebakan yang belum dijawab (atau sekelompok
     * dengannya) berstatus Perlu dicek.
     *
     * @param  array<int, array<string, mixed>>  $mentah
     * @param  array<string, string>  $keputusan  [kunci tebakan => terima|tolak]
     */
    public function periksaBersih(array $mentah, array $keputusan = []): array
    {
        $bersih = $this->bersihkan($mentah, $keputusan);
        [$bersih['mentah'], $digabung] = $this->gabungBaris($bersih['mentah']);
        $tunggu = $this->barisMenunggu($bersih);

        $hasil = array_map(function (array $r) use ($bersih, $tunggu, $digabung) {
            if ($dari = array_keys($digabung, $r['baris'], true)) {
                $r['catatan'][] = 'Digabung dari baris '.implode(', ', [$r['baris'], ...$dari]).', jumlahnya ditambahkan.';
            }

            $info = $bersih['info'][$r['baris']] ?? ['betul' => [], 'tebakan' => []];
            $r['betul'] = $info['betul'];
            $r['tebakan'] = $info['tebakan'];

            $r['tunggu'] = [];

            $tungguSendiri = collect($info['tebakan'])->contains('jawab', null);

            if (isset($tunggu[$r['baris']]) && ($tungguSendiri || $r['status'] !== self::STATUS_DILEWATI)) {
                $r['status'] = self::STATUS_CEK;
                $r['tunggu'] = collect($bersih['info'][$tunggu[$r['baris']]]['tebakan'])->where('jawab', null)->pluck('kunci')->values()->all();

                if (! $tungguSendiri) {
                    $r['pesan'] = ['Ikut menunggu jawaban di baris '.$tunggu[$r['baris']].', karena baris-baris ini disimpan sebagai satu catatan. Jawab pertanyaan di baris itu, lalu baris ini ikut tersimpan.'];
                }
            }

            return $r;
        }, $this->periksa($bersih['mentah']));

        foreach ($digabung as $no => $ke) {
            $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => [], 'pesan' => ['Digabung ke baris '.$ke.'.'],
                'catatan' => [], 'betul' => [], 'tebakan' => [], 'tunggu' => [],
                'ringkas' => [(string) ($mentah[$no]['bahan'] ?? $mentah[$no]['nama'] ?? ''), '']];
        }

        return $hasil;
    }

    /**
     * Simpan dengan jawaban tebakan. Baris yang masih menunggu jawaban (beserta kelompoknya) tidak disimpan.
     *
     * @param  array<int, array<string, mixed>>  $mentah
     * @param  array<string, string>  $keputusan
     * @return array{disimpan: int, dilewati: int, catatan: list<string>}
     */
    public function simpanBersih(array $mentah, array $keputusan, User $oleh, ?string $namaFile): array
    {
        $bersih = $this->bersihkan($mentah, $keputusan);
        [$bersih['mentah'], $digabung] = $this->gabungBaris($bersih['mentah']);
        $tunggu = $this->barisMenunggu($bersih);
        $isi = array_diff_key($bersih['mentah'], $tunggu);

        if ($isi === []) {
            return ['disimpan' => 0, 'dilewati' => count($tunggu) + count($digabung), 'catatan' => []];
        }

        $hasil = $this->simpan($isi, $oleh, $namaFile);
        $hasil['dilewati'] += count($tunggu) + count($digabung);
        $this->ingatNamaLain($bersih['info']);

        return $hasil;
    }

    /** Jawaban "Ya" pada "Maksudnya <bahan>?" diingat sebagai nama lain bahan itu, supaya lain kali langsung dikenali. */
    private function ingatNamaLain(array $info): void
    {
        foreach ($info as $baris) {
            foreach ($baris['tebakan'] ?? [] as $t) {
                if (($t['jawab'] ?? null) === PembersihImpor::TERIMA && ($t['label'] ?? null) === KamusImpor::LABEL[KamusImpor::BAHAN]) {
                    BahanBaku::query()->where('nama', $t['saran'])->first()?->tambahNamaLain((string) $t['asli']);
                }
            }
        }
    }

    /** Kolom jumlah yang boleh ditulis bebas ("2x10 liter", "500 ml") dan dihitung ke satuan bahan. */
    protected function kolomJumlahPintar(): array
    {
        return [];
    }

    /** Baris dengan kunci sama digabung (jumlahnya ditambahkan); null = baris tidak digabung. */
    protected function kunciGabung(array $isi): ?string
    {
        return null;
    }

    /** Kolom satuan yang menyatakan satuan angka di kolom jumlah (mis. Minimum 500 + Satuan gram); null = tidak ada. */
    protected function kolomSatuanJumlah(): ?string
    {
        return null;
    }

    /**
     * Kolom Isi (mis. "10 Liter") dikalikan ke kolom jumlah: jumlah 2 + isi 10 liter = "2 x 10 Liter". Satuan dari kolom
     * Satuan ditempelkan ke angka yang belum bersatuan (500 + gram = "500 gram") supaya ikut dikonversi.
     */
    private function kaliIsiKemasan(array $mentah): array
    {
        $kolom = $this->kolomJumlahPintar();
        $kolomSatuan = $this->kolomSatuanJumlah();

        foreach ($mentah as $no => $isi) {
            $satuan = $kolomSatuan ? trim((string) ($isi[$kolomSatuan] ?? '')) : '';

            foreach ($satuan === '' ? [] : $kolom as $k) {
                $nilai = $isi[$k] ?? null;
                $pecah = filled($nilai) ? JumlahPintar::pecah($nilai) : null;

                if ($pecah !== null && $pecah[1] === null) {
                    $mentah[$no][$k] = (is_float($nilai) ? Angka::isian($nilai) : $nilai).' '.$satuan;
                }
            }

            if ($kolom === [] || blank($isi['isi_kemasan'] ?? null)) {
                continue;
            }

            $k = collect($kolom)->first(fn ($k) => filled($isi[$k] ?? null));
            $jumlah = $k === null ? null : $isi[$k];
            $mentah[$no][$k ?? $kolom[0]] = $jumlah === null ? $isi['isi_kemasan']
                : (is_float($jumlah) ? Angka::isian($jumlah) : $jumlah).' x '.$isi['isi_kemasan'];
        }

        return $mentah;
    }

    /** Jumlah berupa teks ("2 x 10 Liter", "500 ml") dihitung ke satuan bahan baris itu. */
    private function hitungJumlahPintar(array $bersih): array
    {
        $kolom = $this->kolomJumlahPintar();

        if ($kolom === []) {
            return $bersih;
        }

        $bahan = null;

        foreach ($bersih['mentah'] as $no => $isi) {
            foreach ($kolom as $k) {
                $nilai = $isi[$k] ?? null;

                if (! is_string($nilai) || trim($nilai) === '' || is_numeric($nilai)) {
                    continue;
                }

                $bahan ??= BahanBaku::query()->with('satuanBeli:id,nama', 'satuanDasar:id,nama')->get()
                    ->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
                $b = $bahan[mb_strtolower(Str::squish((string) ($isi['bahan'] ?? '')))] ?? null;
                [$angka, , $catatan] = $b ? JumlahPintar::keSatuanBahan($nilai, $b) : [null, null, null];

                if ($b === null && ($pecah = JumlahPintar::pecah($nilai)) && $pecah[1] === null) {
                    [$angka, $catatan] = [$pecah[0], $nilai.' = '.Angka::tampil($pecah[0])];
                }

                if ($angka !== null) {
                    $bersih['mentah'][$no][$k] = $angka;
                    if ($catatan !== null) {
                        $bersih['info'][$no]['betul'][] = $catatan;
                    }
                }
            }
        }

        return $bersih;
    }

    /**
     * Gabungkan baris berkunci sama (kunciGabung): jumlahnya ditambahkan ke baris pertama, baris lain dibuang.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, int>} [isi, [baris dibuang => baris tujuan]]
     */
    private function gabungBaris(array $mentah): array
    {
        $kolom = $this->kolomJumlahPintar();
        $pertama = [];
        $digabung = [];

        foreach ($mentah as $no => $isi) {
            $kunci = $kolom === [] ? null : $this->kunciGabung($isi);

            if ($kunci === null) {
                continue;
            }

            $ke = $pertama[$kunci] ?? null;
            $bisa = $ke !== null && collect($kolom)->every(fn ($k) => ($isi[$k] ?? null) === null || is_numeric($isi[$k]))
                && collect($kolom)->every(fn ($k) => ($mentah[$ke][$k] ?? null) === null || is_numeric($mentah[$ke][$k]))
                && collect($kolom)->contains(fn ($k) => is_numeric($isi[$k] ?? null));

            if (! $bisa) {
                $pertama[$kunci] ??= $no;

                continue;
            }

            foreach ($kolom as $k) {
                if (is_numeric($isi[$k] ?? null)) {
                    $mentah[$ke][$k] = round((float) ($mentah[$ke][$k] ?? 0) + (float) $isi[$k], 3);
                }
            }

            unset($mentah[$no]);
            $digabung[$no] = $ke;
        }

        return [$mentah, $digabung];
    }

    /** @return array<int, int> [nomor baris menunggu => nomor baris yang tebakannya belum dijawab] */
    private function barisMenunggu(array $bersih): array
    {
        $tunggu = [];

        foreach ($bersih['info'] as $no => $info) {
            if (collect($info['tebakan'])->contains('jawab', null)) {
                $tunggu[$no] = $no;
            }
        }

        if ($tunggu === []) {
            return [];
        }

        $kelompok = collect($bersih['mentah'])->map(fn (array $isi) => $this->kunciKelompok($isi));

        foreach ($tunggu as $no) {
            $kunci = $kelompok[$no] ?? null;

            if ($kunci === null) {
                continue;
            }

            foreach ($kelompok as $lain => $k) {
                if ($k === $kunci) {
                    $tunggu[$lain] ??= $no;
                }
            }
        }

        return $tunggu;
    }

    /** Data yang hanya bisa diekspor mengembalikan false. */
    public function bisaImpor(): bool
    {
        return true;
    }

    /**
     * Teks popup unggah: [judul halaman, langkah unduh, tombol unduh].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function teksUnggah(): array
    {
        return [Teks::judul('Impor '.$this->judul()), 'Unduh templat, isi satu baris untuk satu data, lalu unggah di sini. Tabel sendiri juga bisa.', 'Unduh Templat'];
    }

    /**
     * Perkiraan stok per lokasi bila baris yang siap disimpan akan memotong stok (mis. impor penjualan).
     *
     * @param  list<array>  $baris  hasil periksa()
     * @return list<array{lokasi: \App\Models\Cabang, perkiraan: \Illuminate\Support\Collection}>
     */
    public function perkiraanStok(array $baris): array
    {
        return [];
    }

    /**
     * Keterangan tambahan di atas daftar baris pratinjau, mis. bahan yang tidak ada di file.
     *
     * @param  list<array>  $baris  hasil periksa()
     * @return list<string>
     */
    public function catatanPratinjau(array $baris): array
    {
        return [];
    }

    /** Keterangan di pratinjau bila ada baris salah isi. */
    public function teksBarisSalah(): string
    {
        return 'Baris salah isi tidak ikut disimpan. Betulkan di file lalu unggah ulang, atau simpan yang siap dulu.';
    }

    /** Label status dilewati di pratinjau. */
    public function labelDilewati(): string
    {
        return 'Dilewati';
    }

    /** Satuan yang dihitung saat simpan, untuk pesan hasil, mis. "bahan baku" atau "resep menu". */
    public function satuanHasil(): string
    {
        return mb_strtolower($this->judul());
    }

    /**
     * Templat: judul kolom dan beberapa baris contoh berupa data biasa (bukan abu-abu), jadi formatnya terlihat
     * dan templat yang langsung diunggah pun bisa disimpan. Isi contoh dari isiTemplat().
     */
    public function templat(): Spreadsheet
    {
        $kunci = array_keys($this->kolom());
        $isi = array_map(fn (array $baris) => array_map(fn (string $k) => $baris[$k] ?? null, $kunci), $this->isiTemplat());

        return LembarExcel::templat($this->judul(), $this->kolom(), $isi);
    }

    /**
     * Baris contoh templat, [kunci kolom => nilai] per baris. Bawaan: satu baris dari 'contoh' tiap kolom;
     * kelas impor menimpanya dengan beberapa baris (kira-kira 3 per kategori, lihat ContohTemplat).
     *
     * @return list<array<string, mixed>>
     */
    protected function isiTemplat(): array
    {
        return [collect($this->kolom())->map(fn (array $t) => $t['contoh'] ?? null)->all()];
    }

    public function ekspor(): Spreadsheet
    {
        return LembarExcel::templat($this->judul(), $this->kolom(), $this->barisEkspor());
    }

    public function namaFile(string $jenis): string
    {
        return Str::slug(($jenis === 'templat' ? 'templat ' : '').$this->judul()).($jenis === 'templat' ? '' : '-'.now()->format('Y-m-d')).'.xlsx';
    }

    /**
     * Kolom yang ikut dibaca bila ada di file walau tidak ada di templat (mis. kolom Stok di file Unduh Excel).
     *
     * @return list<string>
     */
    protected function kolomTambahan(): array
    {
        return [];
    }

    /** @return array<string, list<string>> [kunci kolom => judul lain yang diterima] */
    protected function sinonimKolom(): array
    {
        return collect($this->kolomBaca())
            ->mapWithKeys(fn (string $k) => [$k => self::SINONIM[$k] ?? []])
            ->all();
    }

    /** Lembar yang dibaca (impor sekaligus); null = lembar pertama yang cocok. */
    protected ?string $lembar = null;

    /** Kolom wajib saat membaca file bila berbeda dari kolomWajib() (impor sekaligus: kolom lain diisi bawaan). */
    protected ?array $wajibBaca = null;

    /** Rentang baris yang dibaca [awal, akhir] (impor sekaligus, beberapa tabel dalam satu lembar); null = semua. */
    protected ?array $rentang = null;

    /** @param  array{0: int, 1: int}|null  $rentang */
    public function diRentang(?array $rentang): static
    {
        $this->rentang = $rentang;

        return $this;
    }

    /** Skor baris sebagai baris judul tabel jenis ini (0 = bukan), lihat LembarExcel::skorJudul. */
    public function skorJudul(array $baris): int
    {
        return LembarExcel::skorJudul($baris, $this->kolomBaca(),
            $this->wajibBaca ?? $this->kolomWajib(), $this->sinonimKolom());
    }

    public function diLembar(?string $lembar): static
    {
        $this->lembar = $lembar;

        return $this;
    }

    /** @param  list<string|list<string>>  $kolom */
    public function wajibBaca(array $kolom): static
    {
        $this->wajibBaca = $kolom;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function bacaFile(UploadedFile $file): array
    {
        return LembarExcel::baca($file, $this->kolomBaca(), $this->wajibBaca ?? $this->kolomWajib(), static::MAKS_BARIS,
            self::barisContoh($this->kolom()), $this->contohDilewati, $this->sinonimKolom(), fn () => $this->pengenalIsi(), $this->lembar,
            $this->rentang);
    }

    /** Kolom yang dibaca dari file: kolom templat, kolom tambahan, dan kolom Isi bila ada kolom jumlah. */
    private function kolomBaca(): array
    {
        return [...array_keys($this->kolom()), ...$this->kolomTambahan(), ...($this->kolomJumlahPintar() === [] ? [] : ['isi_kemasan'])];
    }

    /** Lembar ini punya baris judul dengan semua kolom wajib (tanpa menebak dari isi)? */
    public function punyaJudul(UploadedFile $file, string $lembar): bool
    {
        try {
            LembarExcel::baca($file, [...array_keys($this->kolom()), ...$this->kolomTambahan()], $this->kolomWajib(), PHP_INT_MAX,
                [], $contoh, $this->sinonimKolom(), null, $lembar);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    /**
     * Cara mengenali kolom wajib dari isinya, dipakai bila judul kolomnya tidak dikenal (tabel buatan sendiri):
     * daftar nama yang ada di sistem (bahan, menu, lokasi), 'angka', atau 'tanggal'.
     *
     * @return array<string, list<string>|string>
     */
    protected function pengenalIsi(): array
    {
        $kunci = collect($this->kolomWajib())->flatten()->all();
        $hasil = [];

        foreach ($kunci as $k) {
            $hasil[$k] = match (true) {
                $k === 'bahan'  => BahanBaku::query()->pluck('nama')->all(),
                $k === 'menu'   => Menu::query()->pluck('nama')->all(),
                $k === 'lokasi' => Cabang::query()->get(['nama', 'kode'])->flatMap(fn (Cabang $c) => [$c->nama, $c->kode])->filter()->all(),
                $k === 'tanggal' => 'tanggal',
                in_array($k, self::KOLOM_ANGKA, true) => 'angka',
                default => null,
            };
        }

        return array_filter($hasil, fn ($v) => $v !== null);
    }

    public function contohDilewati(): bool
    {
        return $this->contohDilewati;
    }

    /**
     * Isi baris contoh templat untuk dikenali saat membaca file. Kolom tanggal tidak ikut dibandingkan
     * karena contohnya mengikuti tanggal templat diunduh.
     *
     * @return array<string, mixed>
     */
    public static function barisContoh(array $kolom): array
    {
        return collect($kolom)->reject(fn (array $t) => $t['tanggal'] ?? false)->map(fn (array $t) => $t['contoh'] ?? null)->all();
    }

    /** Pesan untuk pengguna setelah simpan, mis. "3 bahan baku disimpan, 1 kategori baru. 2 baris dilewati." */
    public function pesanSimpan(array $hasil): string
    {
        $pesan = collect([$hasil['disimpan'].' '.$this->satuanHasil().' disimpan', ...$hasil['catatan']])->implode(', ').'.';

        return $hasil['dilewati'] > 0 ? $pesan.' '.$hasil['dilewati'].' baris dilewati.' : $pesan;
    }

    /**
     * Pesan galat validasi Laravel untuk satu baris.
     *
     * @return list<string>
     */
    protected function validasi(array $data, array $aturan, array $pesan = [], array $atribut = []): array
    {
        return Validator::make($data, $aturan, $pesan, $atribut)->errors()->all();
    }

    /** Isian teks: rapikan spasi, kosong menjadi null. */
    protected static function teks(mixed $nilai): ?string
    {
        $teks = Str::squish((string) $nilai);

        return $teks === '' ? null : $teks;
    }

    /** Catat impor di log perubahan. */
    protected function catatLog(User $oleh, int $jumlah, ?string $namaFile): void
    {
        $this->log->catat($oleh, LogPerubahan::KATEGORI_SISTEM, 'impor.'.Str::slug($this->judul()),
            'Impor '.$jumlah.' '.$this->satuanHasil().($namaFile ? ' dari '.$namaFile : '').'.');
    }
}
