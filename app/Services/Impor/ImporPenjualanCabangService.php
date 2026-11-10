<?php

namespace App\Services\Impor;

use App\Support\ContohTemplat;
use App\Http\Requests\Cabang\PenjualanRequest;
use App\Models\Cabang;
use App\Models\Menu;
use App\Models\PenjualanHarian;
use App\Models\Resep;
use App\Models\User;
use App\Services\EksporDaftarService;
use App\Services\LogPerubahanService;
use App\Services\PenjualanService;
use App\Services\PeriodeBukuService;
use App\Services\PerkiraanStokService;
use App\Support\Angka;
use App\Support\BukuExcel;
use App\Support\HariOperasional;
use App\Support\LembarExcel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Impor penjualan harian oleh Admin Cabang untuk cabangnya sendiri: satu file = satu catatan penjualan
 * (menu + porsi; kolom Tanggal boleh tidak ada = hari ini). Hasilnya sama dengan mengisi form penjualan:
 * catatan tercatat atas nama Admin Cabang (dicatatCabang), bahan dari resep berkurang lewat PenjualanService.
 *
 * Aturan form tetap berlaku: tanggal paling lama PenjualanHarian::BATAS_MUNDUR_HARI hari ke belakang dan tidak
 * setelah hari ini, satu catatan per hari per cabang, dan tanggal yang sudah tutup buku ditolak. Bila tanggal itu
 * sudah dicatat cabang dan masih bisa diubah, impor mengubah catatan itu (menu di file mengganti porsinya, menu lain
 * tetap, perubahan masuk log). Catatan hasil impor Admin Utama atau yang lewat batas ubah tidak bisa diganti.
 */
class ImporPenjualanCabangService extends ImporExcel
{
    private ?Cabang $lokasi = null;

    private ?PenjualanHarian $catatan = null;

    public function __construct(LogPerubahanService $log, private PenjualanService $penjualan, private PerkiraanStokService $perkiraan, private PeriodeBukuService $periode)
    {
        parent::__construct($log);
    }

    public const MODE_GANTI = 'ganti';
    public const MODE_TAMBAH = 'tambah';

    public function pilihanMode(): array
    {
        return [
            self::MODE_GANTI  => ['Ganti'],
            self::MODE_TAMBAH => ['Tambah'],
        ];
    }

    private function tambah(): bool
    {
        return $this->mode() === self::MODE_TAMBAH;
    }

    /** Cabang pemilik penjualan. Wajib dipanggil sebelum templat, periksa, atau simpan. */
    public function diLokasi(Cabang $lokasi): static
    {
        $this->lokasi = $lokasi;

        return $this;
    }

    public function judul(): string
    {
        return 'Penjualan';
    }

    public function satuanHasil(): string
    {
        return 'menu';
    }

    public function teksUnggah(): array
    {
        if ($this->catatan) {
            return [
                'Ubah Lewat Excel',
                'Unduh file penjualan '.$this->catatan->tanggal->translatedFormat('j M Y').' (berisi porsi yang sudah dicatat), ubah angka di kolom Porsi, lalu unggah. Menu yang dikosongkan tetap seperti semula; tulis 0 untuk menghapus porsinya.',
                'Unduh File Penjualan',
            ];
        }

        return [
            'Impor Penjualan',
            'Unduh templat (format sama dengan Unduh Excel, berisi semua menu dan tanggal hari ini), isi kolom Porsi, lalu unggah. Tanpa kolom Tanggal, penjualan dicatat untuk hari ini (sebelum jam '.HariOperasional::jamGanti().' masih dianggap kemarin). File dari kasir juga bisa, asal ada kolom Menu dan Porsi/Qty.',
            'Unduh Templat',
        ];
    }

    /** Kolom sama dengan file Unduh Excel penjualan (Tanggal, Kategori Menu, Menu, Porsi). */
    public function kolom(): array
    {
        return [
            'tanggal'       => ['lebar' => 14, 'ket' => 'Tanggal penjualan, sama untuk semua baris. Kosong = hari ini (sebelum jam '.HariOperasional::jamGanti().' = kemarin). Paling lama '.PenjualanHarian::BATAS_MUNDUR_HARI.' hari ke belakang.', 'contoh' => now()->toDateString(), 'tanggal' => true],
            'kategori_menu' => ['lebar' => 18, 'ket' => 'Hanya keterangan, tidak dibaca.', 'contoh' => 'Ramen', 'info' => true],
            'menu'          => ['lebar' => 30, 'ket' => 'Wajib. Nama menu seperti di daftar menu.', 'contoh' => 'Shoyu Ramen'],
            'porsi'         => ['lebar' => 10, 'ket' => 'Jumlah porsi terjual, bilangan bulat. Kosong = menu tidak terjual.', 'contoh' => 25],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['menu', 'porsi'];
    }

    protected function kolomTambahan(): array
    {
        return ['lokasi'];
    }

    /** Satu file = satu catatan penjualan, jadi satu tebakan yang belum dijawab menahan seluruh file. */
    /** Menu dengan porsi kosong atau 0 dilewati, jadi salah ketik namanya tidak perlu ditanyakan. */
    protected function barisTanpaTanya(array $isi): bool
    {
        return blank($isi['porsi'] ?? null) || (is_numeric($isi['porsi']) && (float) $isi['porsi'] === 0.0);
    }

    protected function kunciKelompok(array $isi): ?string
    {
        return 'penjualan';
    }

    public function namaFile(string $jenis): string
    {
        return BukuExcel::namaBerkas('penjualan', $this->lokasiDipakai()->nama, ($this->catatan?->tanggal ?? HariOperasional::tanggal())->toDateString());
    }

    /** Catatan yang akan diubah lewat Excel: templat berisi tanggal dan porsi catatan itu. */
    public function untukCatatan(?PenjualanHarian $catatan): static
    {
        $this->catatan = $catatan;

        return $this;
    }

    /**
     * Templat = format Unduh Excel penjualan, berisi semua menu aktif per kategori dengan tanggal hari ini dan porsi
     * contoh (data biasa, bisa langsung diunggah); atau tanggal dan porsi catatan yang sedang diubah (untukCatatan).
     */
    public function templat(): Spreadsheet
    {
        $menu = Menu::query()->aktif()->with('kategoriMenu:id,nama,urutan')->get()
            ->sortBy(fn (Menu $m) => [$m->kategoriMenu->urutan, $m->kategoriMenu->nama, $m->nama])
            ->values();
        $porsi = $this->catatan
            ? $this->catatan->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->all()
            : $menu->values()->mapWithKeys(fn (Menu $m, int $i) => [$m->id => ContohTemplat::angka($i, [25, 18, 12, 20, 15, 9, 30, 14, 22])])->all();

        return app(EksporDaftarService::class)->templatPenjualan($this->lokasiDipakai(), $menu, $this->catatan?->tanggal, $porsi);
    }

    protected function barisEkspor(): array
    {
        return [];
    }

    public function periksa(array $mentah): array
    {
        $lokasi = $this->lokasiDipakai();
        $menu = Menu::query()->get()->keyBy(fn (Menu $m) => mb_strtolower($m->nama));
        $resepPertama = Resep::query()->groupBy('menu_id')->selectRaw('menu_id, MIN(berlaku_sejak) AS tanggal')->pluck('tanggal', 'menu_id')
            ->map(fn ($t) => Carbon::parse($t)->toDateString());
        $namaLokasi = [mb_strtolower($lokasi->kode), mb_strtolower($lokasi->nama)];
        $hariIni = HariOperasional::tanggalTeks();
        $baris = [];
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $namaMenu = Str::squish((string) $isi['menu']);
            $m = $menu[mb_strtolower($namaMenu)] ?? null;
            $teksLokasi = Str::squish((string) ($isi['lokasi'] ?? ''));
            $tanggal = filled($isi['tanggal'] ?? null) ? LembarExcel::tanggal($isi['tanggal']) : $hariIni;
            $porsi = Angka::baca($isi['porsi']);

            $data = [
                'tanggal' => $tanggal ?? (string) $isi['tanggal'],
                'menu'    => $m?->nama ?? $namaMenu,
                'menu_id' => $m?->id,
                'porsi'   => is_numeric($porsi) ? (int) $porsi : null,
            ];

            if ($teksLokasi !== '' && ! in_array(mb_strtolower($teksLokasi), $namaLokasi, true)) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ["Baris untuk lokasi {$teksLokasi}, bukan {$lokasi->nama}."], 'catatan' => []];

                continue;
            }

            if (blank($isi['porsi'])) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data, 'pesan' => ['Porsi kosong.'], 'catatan' => []];

                continue;
            }

            if (is_numeric($porsi) && (float) $porsi === 0.0 && (! $m || ! $m->aktif)) {
                $hasil[] = ['baris' => $no, 'status' => self::STATUS_DILEWATI, 'data' => $data,
                    'pesan' => [$m ? 'Porsi 0, menu sudah tidak dijual.' : 'Porsi 0, menu tidak ada di daftar menu.'], 'catatan' => []];

                continue;
            }

            $pesan = [];
            $catatan = [];

            if ($tanggal === null) {
                $pesan[] = 'Tanggal tidak terbaca. Pakai HH/BB/TTTT, mis. '.now()->format('d/m/Y').'.';
            }

            if (! $m) {
                $pesan[] = $namaMenu === '' ? 'Nama menu wajib diisi.' : "Menu {$namaMenu} tidak ada di daftar menu.";
            } elseif (! $m->aktif) {
                $pesan[] = "Menu {$m->nama} sudah tidak dijual (nonaktif). Kosongkan porsinya, atau minta Admin Utama mengaktifkan menu itu lagi.";
            } elseif ($tanggal !== null && ($resepPertama[$m->id] ?? '9999-12-31') > $tanggal) {
                $catatan[] = 'Menu ini belum punya resep di tanggal itu, jadi stok bahan tidak berkurang.';
            }

            if (! is_numeric($porsi) || (float) $porsi != (int) $porsi || $porsi < 0 || $porsi > PenjualanRequest::MAKS_PORSI) {
                $pesan[] = 'Porsi diisi bilangan bulat 0 sampai '.Angka::tampil(PenjualanRequest::MAKS_PORSI).'.';
            }

            $baris[] = ['no' => $no, 'data' => $data, 'pesan' => $pesan, 'catatan' => $catatan];
        }

        return [...$hasil, ...$this->periksaCatatan(collect($baris))];
    }

    /**
     * Aturan satu catatan: satu tanggal, batas tanggal, tutup buku, menu ganda, catatan yang sudah ada.
     * Bila ada satu baris salah, seluruh catatan tidak disimpan supaya angkanya tidak setengah jadi.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $baris
     */
    private function periksaCatatan($baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }

        $pesanUmum = [];
        $catatanUmum = [];
        $tanggalFile = $baris->pluck('data.tanggal')->filter(fn ($t) => strlen((string) $t) === 10)->unique()->values();
        $tanggal = $tanggalFile->count() === 1 ? $tanggalFile->first() : null;

        if ($tanggalFile->count() > 1) {
            $pesanUmum[] = 'Satu file untuk satu tanggal. File ini berisi '.$tanggalFile->map(fn ($t) => Carbon::parse($t)->format('d/m/Y'))->implode(', ').'.';
        } elseif ($tanggal !== null) {
            $pesanUmum = [...$pesanUmum, ...$this->pesanTanggal($tanggal)];
        }

        $ada = $tanggal ? PenjualanHarian::query()->diLokasi($this->lokasiDipakai()->id)->whereDate('tanggal', $tanggal)->with('detail:id,penjualan_harian_id,menu_id,porsi')->first() : null;
        $porsiLama = $ada?->detail->pluck('porsi', 'menu_id') ?? collect();

        if ($ada && ! $ada->dicatatCabang()) {
            $pesanUmum[] = 'Penjualan tanggal ini diimpor Admin Utama, jadi tidak bisa diubah dari cabang.';
        } elseif ($ada && ! $ada->bisaDiubah()) {
            $pesanUmum[] = 'Penjualan tanggal ini sudah dicatat dan melewati batas waktu perubahan.';
        } elseif ($ada) {
            $catatanUmum[] = 'Mengubah catatan '.$ada->tanggal->translatedFormat('j M Y').': porsi menu di file '
                .($this->tambah() ? 'ditambahkan ke porsi yang sudah ada' : 'mengganti yang lama').', menu lain tetap.';
        } elseif ($baris->every(fn ($r) => $r['data']['porsi'] === 0)) {
            $pesanUmum[] = 'Isi porsi minimal satu menu.';
        }

        $sudah = [];
        $baris = $baris->map(function (array $r) use (&$sudah) {
            $id = $r['data']['menu_id'];

            if ($id && isset($sudah[$id])) {
                $r['pesan'][] = "Menu {$r['data']['menu']} sudah ada di baris {$sudah[$id]}.";
            }

            $sudah[$id] ??= $r['no'];

            return $r;
        });

        $salah = $pesanUmum !== [] || $baris->contains(fn ($r) => $r['pesan'] !== []);

        $tambah = $this->tambah();

        return $baris->values()->map(function (array $r, int $i) use ($salah, $pesanUmum, $catatanUmum, $porsiLama, $ada, $tambah) {
            $pesan = $i === 0 ? [...$pesanUmum, ...$r['pesan']] : $r['pesan'];

            if ($salah && $pesan === []) {
                $pesan = ['Penjualan tidak disimpan karena ada baris lain yang salah.'];
            }

            $lama = $ada && $r['data']['menu_id'] ? (int) ($porsiLama[$r['data']['menu_id']] ?? 0) : null;
            $ubah = match (true) {
                $lama === null => [],
                $tambah && $lama > 0 => ['Sebelumnya '.Angka::tampil($lama).' porsi, menjadi '.Angka::tampil($lama + (int) $r['data']['porsi']).' porsi.'],
                ! $tambah && $lama !== $r['data']['porsi'] => ['Sebelumnya '.Angka::tampil($lama).' porsi.'],
                default => [],
            };
            $catatan = [...($i === 0 ? $catatanUmum : []), ...$r['catatan'], ...$ubah];

            return [
                'baris'   => $r['no'],
                'status'  => $salah ? self::STATUS_SALAH : self::STATUS_SIAP,
                'data'    => $r['data'],
                'pesan'   => $pesan,
                'catatan' => $salah ? [] : $catatan,
            ];
        })->all();
    }

    /** @return list<string> sama dengan aturan tanggal di PenjualanRequest */
    private function pesanTanggal(string $tanggal): array
    {
        if ($tanggal > now()->toDateString()) {
            return ['Tanggal penjualan tidak boleh setelah hari ini.'];
        }

        if ($tanggal < now()->subDays(PenjualanHarian::BATAS_MUNDUR_HARI)->toDateString()) {
            return ['Penjualan hanya bisa dicatat paling lama '.PenjualanHarian::BATAS_MUNDUR_HARI.' hari ke belakang. Hubungi Admin Utama untuk tanggal yang lebih lama.'];
        }

        if ($this->periode->penjualanTertutup($this->lokasiDipakai()->id, $tanggal)) {
            return [PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP];
        }

        return [];
    }

    /**
     * Porsi yang akan disimpan: [menu_id => porsi]; bila catatan tanggal itu sudah ada, digabung dengan isinya
     * (Ganti: porsi menu di file menggantikan; Tambah: porsi di file ditambahkan).
     *
     * @param  list<array>  $siap
     * @return array{0: string, 1: array<int, int>, 2: ?PenjualanHarian}
     */
    private function rencana(array $siap): array
    {
        $tanggal = $siap[0]['data']['tanggal'];
        $ada = PenjualanHarian::query()->diLokasi($this->lokasiDipakai()->id)->whereDate('tanggal', $tanggal)->first();
        $porsi = $ada ? $ada->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->all() : [];

        foreach ($siap as $r) {
            $porsi[$r['data']['menu_id']] = ($this->tambah() ? ($porsi[$r['data']['menu_id']] ?? 0) : 0) + $r['data']['porsi'];
        }

        return [$tanggal, $porsi, $ada];
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(function () use ($mentah, $oleh, $namaFile) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP)->values()->all();

            if ($siap === []) {
                return ['disimpan' => 0, 'dilewati' => $hasil->count(), 'catatan' => []];
            }

            [$tanggal, $porsi, $ada] = $this->rencana($siap);

            $ada
                ? $this->penjualan->ubah($ada, $oleh, $porsi)
                : $this->penjualan->catat($oleh, $this->lokasiDipakai(), $tanggal, $porsi);

            $this->catatLog($oleh, count($siap), $namaFile);

            return [
                'disimpan' => count($siap),
                'dilewati' => $hasil->count() - count($siap),
                'catatan'  => [($ada ? 'catatan ' : 'penjualan ').Carbon::parse($tanggal)->translatedFormat('j M Y').($ada ? ' diubah' : ' tercatat')],
            ];
        });
    }

    public function pesanSimpan(array $hasil): string
    {
        return 'Penjualan '.$hasil['disimpan'].' menu disimpan ('.implode(', ', $hasil['catatan']).'). Pemakaian bahan sudah dikurangkan dari stok.';
    }

    public function perkiraanStok(array $baris): array
    {
        $siap = array_values(array_filter($baris, fn ($r) => $r['status'] === self::STATUS_SIAP));

        if ($siap === []) {
            return [];
        }

        [$tanggal, $porsi, $ada] = $this->rencana($siap);
        $perkiraan = $this->perkiraan->penjualan($this->lokasiDipakai(), [['tanggal' => $tanggal, 'porsi' => $porsi, 'ganti' => $ada]]);

        return $perkiraan->isEmpty() ? [] : [['lokasi' => $this->lokasiDipakai(), 'perkiraan' => $perkiraan]];
    }

    public function ringkasan(array $data): array
    {
        $tanggal = strlen((string) $data['tanggal']) === 10 ? Carbon::parse($data['tanggal'])->translatedFormat('j M Y') : $data['tanggal'];

        return [
            $data['menu'] !== '' ? $data['menu'] : '(tanpa nama menu)',
            collect([$tanggal, $data['porsi'] !== null ? Angka::tampil($data['porsi']).' porsi' : null])->filter()->implode(' · '),
        ];
    }

    private function lokasiDipakai(): Cabang
    {
        return $this->lokasi ?? throw new LogicException('Cabang impor penjualan belum dipilih.');
    }
}
