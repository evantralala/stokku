<?php

namespace App\Services\Impor;

use App\Http\Requests\Cabang\PenjualanRequest;
use App\Models\Cabang;
use App\Models\Menu;
use App\Models\PenjualanDetail;
use App\Models\PenjualanHarian;
use App\Models\Resep;
use App\Models\User;
use App\Services\LogPerubahanService;
use App\Services\PenjualanService;
use App\Services\PeriodeBukuService;
use App\Services\PerkiraanStokService;
use App\Support\Angka;
use App\Support\ContohTemplat;
use App\Support\HariOperasional;
use App\Support\LembarExcel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor penjualan harian dari Excel oleh Admin Utama. Satu baris = porsi satu menu di satu cabang pada satu tanggal.
 * Baris dengan cabang dan tanggal yang sama menjadi satu penjualan (tanggal kosong = hari ini).
 * Tanggal yang sudah dicatat Admin Cabang dilewati (catatan cabang tidak pernah ditimpa); tanggal yang sebelumnya diimpor diganti isinya.
 * Pemakaian bahan dihitung dari resep yang berlaku di tanggal penjualan, lewat PenjualanService.
 * Bila satu baris salah, penjualan cabang di tanggal itu tidak disimpan supaya angkanya tidak setengah jadi.
 */
class ImporPenjualanService extends ImporExcel
{
    public function __construct(LogPerubahanService $log, private PenjualanService $penjualan, private PerkiraanStokService $perkiraan, private PeriodeBukuService $periode)
    {
        parent::__construct($log);
    }

    public function judul(): string
    {
        return 'Penjualan';
    }

    public function satuanHasil(): string
    {
        return 'penjualan harian';
    }

    protected function isiTemplat(): array
    {
        $lokasi = ContohTemplat::kodeLokasi(Cabang::JENIS_CABANG);

        return ContohTemplat::menu()->values()->map(fn (array $m, int $i) => [
            'tanggal' => HariOperasional::tanggalTeks(), 'lokasi' => $lokasi, 'menu' => $m['nama'], 'porsi' => ContohTemplat::angka($i, [25, 18, 12, 20, 15, 9, 30, 14, 22]),
        ])->all();
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

    public function kolom(): array
    {
        return [
            'tanggal' => ['lebar' => 14, 'contoh' => now()->toDateString(), 'ket' => 'Boleh kosong (hari ini). HH/BB/TTTT, mis. '.now()->format('d/m/Y').'. Paling lama '.PenjualanHarian::BATAS_MUNDUR_IMPOR_HARI.' hari ke belakang.', 'tanggal' => true],
            'lokasi'  => ['lebar' => 18, 'ket' => 'Wajib. Kode atau nama cabang, mis. KNG.', 'contoh' => 'KNG'],
            'menu'    => ['lebar' => 28, 'ket' => 'Wajib. Nama menu persis seperti di daftar menu.', 'contoh' => 'Shoyu Ramen'],
            'porsi'   => ['lebar' => 10, 'ket' => 'Wajib. Jumlah porsi terjual, bilangan bulat. Kosong = dilewati.', 'contoh' => 25],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['tanggal', 'lokasi', 'menu', 'porsi'];
    }

    /** Semua menu satu lokasi pada satu tanggal menjadi satu catatan penjualan. */
    /** Menu dengan porsi kosong atau 0 dilewati, jadi salah ketik namanya tidak perlu ditanyakan. */
    protected function barisTanpaTanya(array $isi): bool
    {
        return blank($isi['porsi'] ?? null) || (is_numeric($isi['porsi']) && (float) $isi['porsi'] === 0.0);
    }

    protected function kunciKelompok(array $isi): ?string
    {
        return mb_strtolower(($isi['tanggal'] ?? '').'|'.($isi['lokasi'] ?? ''));
    }

    protected function barisEkspor(): array
    {
        return PenjualanDetail::query()
            ->join('penjualan_harian', 'penjualan_harian.id', '=', 'penjualan_detail.penjualan_harian_id')
            ->join('cabang', 'cabang.id', '=', 'penjualan_harian.cabang_id')
            ->join('menu', 'menu.id', '=', 'penjualan_detail.menu_id')
            ->whereNull('penjualan_harian.deleted_at')
            ->whereDate('penjualan_harian.tanggal', '>=', now()->subDays(PenjualanHarian::BATAS_MUNDUR_IMPOR_HARI)->toDateString())
            ->orderByDesc('penjualan_harian.tanggal')
            ->orderBy('cabang.nama')
            ->orderBy('menu.nama')
            ->get(['penjualan_harian.tanggal', 'cabang.kode', 'menu.nama', 'penjualan_detail.porsi'])
            ->map(fn ($r) => [Carbon::parse($r->tanggal)->toDateString(), $r->kode, $r->nama, (int) $r->porsi])
            ->all();
    }

    public function periksa(array $mentah): array
    {
        $lokasi = Cabang::query()->cabang()->get();
        $kodeLokasi = $lokasi->keyBy(fn (Cabang $l) => mb_strtolower($l->kode));
        $namaLokasi = $lokasi->keyBy(fn (Cabang $l) => mb_strtolower($l->nama));
        $menu = Menu::query()->get()->keyBy(fn (Menu $m) => mb_strtolower($m->nama));
        $resepPertama = Resep::query()->groupBy('menu_id')->selectRaw('menu_id, MIN(berlaku_sejak) AS tanggal')->pluck('tanggal', 'menu_id')
            ->map(fn ($t) => Carbon::parse($t)->toDateString());
        $hariIni = HariOperasional::tanggalTeks();
        $batasAwal = now()->subDays(PenjualanHarian::BATAS_MUNDUR_IMPOR_HARI)->toDateString();

        $siapPeriksa = [];
        $hasil = [];

        foreach ($mentah as $no => $isi) {
            $namaMenu = Str::squish((string) $isi['menu']);
            $teksLokasi = mb_strtolower(Str::squish((string) $isi['lokasi']));
            $l = $kodeLokasi[$teksLokasi] ?? $namaLokasi[$teksLokasi] ?? null;
            $m = $menu[mb_strtolower($namaMenu)] ?? null;
            $tanggal = filled($isi['tanggal']) ? LembarExcel::tanggal($isi['tanggal']) : $hariIni;
            $porsi = Angka::baca($isi['porsi']);

            $data = [
                'tanggal'   => $tanggal ?? (string) $isi['tanggal'],
                'lokasi'    => $l?->nama ?? Str::squish((string) $isi['lokasi']),
                'cabang_id' => $l?->id,
                'menu'      => $m?->nama ?? $namaMenu,
                'menu_id'   => $m?->id,
                'porsi'     => is_numeric($porsi) ? (int) $porsi : null,
            ];

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
                $pesan[] = 'Tanggal tidak terbaca. Pakai HH/BB/TTTT, mis. 16/10/2026.';
            } elseif ($tanggal > $hariIni) {
                $pesan[] = 'Tanggal penjualan tidak boleh setelah hari ini.';
            } elseif ($tanggal < $batasAwal) {
                $pesan[] = 'Penjualan hanya bisa diimpor paling lama '.PenjualanHarian::BATAS_MUNDUR_IMPOR_HARI.' hari ke belakang.';
            }

            if (! $l) {
                $pesan[] = $teksLokasi === '' ? 'Lokasi wajib diisi.' : 'Cabang '.Str::squish((string) $isi['lokasi']).' tidak dikenal.';
            } elseif (! $l->aktif) {
                $pesan[] = "{$l->nama} sedang nonaktif.";
            } elseif ($l->opname_aktif) {
                $pesan[] = "{$l->nama} sedang stock opname.";
            } elseif ($tanggal !== null && $this->periode->penjualanTertutup($l->id, $tanggal)) {
                $pesan[] = PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP;
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

            $siapPeriksa[] = ['no' => $no, 'data' => $data, 'pesan' => $pesan, 'catatan' => $catatan];
        }

        $kelompok = collect($siapPeriksa)->groupBy(fn ($r) => $r['data']['cabang_id'] && strlen($r['data']['tanggal']) === 10
            ? $r['data']['cabang_id'].'|'.$r['data']['tanggal']
            : 'salah|'.$r['no']);

        $tercatat = PenjualanHarian::query()
            ->with(['pencatat:id,role', 'detail:id,penjualan_harian_id,menu_id,porsi'])
            ->whereDate('tanggal', '>=', $batasAwal)
            ->get(['id', 'cabang_id', 'tanggal', 'dicatat_oleh'])
            ->keyBy(fn (PenjualanHarian $p) => $p->cabang_id.'|'.$p->tanggal->toDateString());
        $sudahAda = $tercatat->map(fn (PenjualanHarian $p) => $p->pencatat?->role === User::ROLE_ADMIN_CABANG);

        foreach ($kelompok as $kunci => $daftar) {
            $pesanKelompok = [];
            $sudah = [];

            foreach ($daftar as $i => $r) {
                $menuId = $r['data']['menu_id'];

                if ($menuId && isset($sudah[$menuId])) {
                    $daftar[$i] = ['pesan' => [...$r['pesan'], "Menu {$r['data']['menu']} sudah ada di baris {$sudah[$menuId]}."]] + $r;
                }

                $sudah[$menuId] ??= $r['no'];
            }

            if ($sudahAda[$kunci] ?? false) {
                $porsiCabang = $tercatat[$kunci]->detail->pluck('porsi', 'menu_id');

                foreach ($daftar->values() as $r) {
                    $cabang = (int) ($porsiCabang[$r['data']['menu_id']] ?? 0);
                    $beda = $r['data']['porsi'] !== null && $r['data']['porsi'] !== $cabang;

                    $hasil[] = [
                        'baris'   => $r['no'],
                        'status'  => self::STATUS_DILEWATI,
                        'data'    => $r['data'],
                        'pesan'   => [
                            'Penjualan '.$r['data']['lokasi'].' tanggal ini sudah dicatat cabang, jadi tidak diganti.',
                            ...($beda ? ['Beda dengan catatan cabang: cabang '.Angka::tampil($cabang).' porsi, file '.Angka::tampil($r['data']['porsi']).' porsi. Periksa dengan cabang.'] : []),
                        ],
                        'catatan' => [],
                    ];
                }

                continue;
            }

            if ($daftar->every(fn ($r) => $r['data']['porsi'] === 0)) {
                $pesanKelompok[] = 'Isi porsi minimal satu menu.';
            }

            $salah = $pesanKelompok !== [] || $daftar->contains(fn ($r) => $r['pesan'] !== []);
            $catatanKelompok = isset($sudahAda[$kunci])
                ? [$this->tambah() ? 'Ditambahkan ke penjualan hasil impor sebelumnya' : 'Mengganti penjualan hasil impor sebelumnya']
                : [];

            foreach ($daftar->values() as $i => $r) {
                $pesan = $i === 0 ? [...$pesanKelompok, ...$r['pesan']] : $r['pesan'];

                if ($salah && $pesan === []) {
                    $pesan = ['Penjualan cabang di tanggal ini tidak disimpan karena ada baris lain yang salah.'];
                }

                $hasil[] = [
                    'baris'   => $r['no'],
                    'status'  => $salah ? self::STATUS_SALAH : self::STATUS_SIAP,
                    'data'    => $r['data'],
                    'pesan'   => $pesan,
                    'catatan' => $salah ? [] : ($i === 0 ? [...$catatanKelompok, ...$r['catatan']] : $r['catatan']),
                ];
            }
        }

        return $hasil;
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(function () use ($mentah, $oleh, $namaFile) {
            $hasil = collect($this->periksa($mentah));
            $siap = $hasil->where('status', self::STATUS_SIAP)
                ->groupBy(fn ($r) => $r['data']['cabang_id'].'|'.$r['data']['tanggal']);
            $diganti = 0;

            foreach ($siap as $daftar) {
                $pertama = $daftar->first()['data'];
                $lokasi = Cabang::findOrFail($pertama['cabang_id']);
                $ada = PenjualanHarian::query()->diLokasi($lokasi->id)
                    ->whereDate('tanggal', $pertama['tanggal'])->exists();

                $this->penjualan->terapkanImpor($lokasi, $pertama['tanggal'], $this->porsiAkhir($lokasi, $pertama['tanggal'], $daftar), $oleh);

                $diganti += $ada ? 1 : 0;
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return [
                'disimpan' => $siap->count(),
                'dilewati' => $hasil->where('status', '!=', self::STATUS_SIAP)->count(),
                'catatan'  => $diganti ? [$diganti.' di antaranya '.($this->tambah() ? 'ditambahkan ke' : 'mengganti').' hasil impor sebelumnya'] : [],
            ];
        });
    }

    public function perkiraanStok(array $baris): array
    {
        $siap = collect($baris)->where('status', self::STATUS_SIAP)->groupBy('data.cabang_id');
        $lokasi = Cabang::query()->whereKey($siap->keys()->all())->orderBy('nama')->get();

        return $lokasi->map(fn (Cabang $l) => [
            'lokasi'    => $l,
            'perkiraan' => $this->perkiraan->penjualan($l, $siap[$l->id]->groupBy('data.tanggal')
                ->map(fn ($daftar, string $tanggal) => [
                    'tanggal' => $tanggal,
                    'porsi'   => $this->porsiAkhir($l, $tanggal, $daftar),
                    'ganti'   => PenjualanHarian::query()->diLokasi($l->id)->whereDate('tanggal', $tanggal)->first(),
                ])
                ->values()
                ->all()),
        ])->filter(fn (array $p) => $p['perkiraan']->isNotEmpty())->values()->all();
    }

    /**
     * Porsi akhir satu catatan [menu_id => porsi]. Ganti: isi file saja (catatan hasil impor sebelumnya diganti seluruhnya).
     * Tambah: porsi catatan yang sudah ada ditambah porsi di file.
     */
    private function porsiAkhir(Cabang $lokasi, string $tanggal, $daftar): array
    {
        $file = collect($daftar)->mapWithKeys(fn ($r) => [$r['data']['menu_id'] => (int) $r['data']['porsi']])->all();

        if (! $this->tambah()) {
            return $file;
        }

        $porsi = PenjualanHarian::query()->diLokasi($lokasi->id)->whereDate('tanggal', $tanggal)->first()
            ?->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->all() ?? [];

        foreach ($file as $menu => $jumlah) {
            $porsi[$menu] = ($porsi[$menu] ?? 0) + $jumlah;
        }

        return $porsi;
    }

    public function ringkasan(array $data): array
    {
        $tanggal = strlen($data['tanggal']) === 10 ? Carbon::parse($data['tanggal'])->translatedFormat('j M Y') : $data['tanggal'];

        return [
            $data['menu'] !== '' ? $data['menu'] : '(tanpa nama menu)',
            collect([$data['lokasi'], $tanggal, $data['porsi'] !== null ? Angka::tampil($data['porsi']).' porsi' : null])->filter()->implode(' · '),
        ];
    }
}
