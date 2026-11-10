<?php

namespace App\Services\Impor;

use App\Http\Requests\AdminUtama\KategoriMenuRequest;
use App\Http\Requests\AdminUtama\MenuRequest;
use App\Http\Requests\AdminUtama\ResepRequest;
use App\Models\BahanBaku;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Resep;
use App\Models\User;
use App\Services\LogPerubahanService;
use App\Services\ResepService;
use App\Support\Angka;
use App\Support\ContohTemplat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor & ekspor menu beserta resepnya. Satu baris = satu bahan dalam resep satu menu (takaran per porsi, satuan dasar).
 * Baris dengan menu yang sama digabung menjadi satu versi resep yang berlaku mulai hari impor (jadwal resep untuk
 * tanggal lain lewat form resep). Resep yang sama persis dengan versi terbaru dilewati. Menu yang belum ada dibuat (butuh kategori_menu); menu tanpa bahan boleh (resep kosong).
 * Bila satu baris salah, seluruh resep menu itu tidak disimpan supaya resep tidak setengah jadi.
 */
class ImporResepService extends ImporExcel
{
    public function __construct(LogPerubahanService $log, private ResepService $resep)
    {
        parent::__construct($log);
    }

    public function judul(): string
    {
        return 'Menu & resep';
    }

    public function satuanHasil(): string
    {
        return 'resep menu';
    }

    protected function isiTemplat(): array
    {
        $satuan = collect(ContohTemplat::BAHAN)->mapWithKeys(fn (array $b) => [$b[0] => $b[2]]);

        return array_map(fn (array $r) => [
            'menu' => $r[0], 'kategori_menu' => $r[1], 'bahan' => $r[2], 'takaran' => $r[3], 'satuan' => $satuan[$r[2]] ?? null,
        ], ContohTemplat::RESEP);
    }

    public function kolom(): array
    {
        return [
            'menu'          => ['lebar' => 26, 'ket' => 'Wajib. Nama menu. Tulis ulang di setiap baris bahannya.', 'contoh' => 'Shoyu Ramen'],
            'kategori_menu' => ['lebar' => 18, 'ket' => 'Wajib untuk menu baru, mis. Ramen. Yang belum ada dibuat otomatis.', 'contoh' => 'Ramen'],
            'bahan'         => ['lebar' => 26, 'ket' => 'Nama bahan baku persis seperti di daftar bahan. Kosong = menu tanpa resep.', 'contoh' => 'Mie Ramen'],
            'takaran'       => ['lebar' => 12, 'ket' => 'Takaran untuk 1 porsi dalam satuan resep (lihat kolom satuan: kg ditulis gram, liter ditulis ml), mis. 150.', 'contoh' => 150],
            'satuan'        => ['lebar' => 10, 'ket' => 'Hanya keterangan: satuan resep bahan, tidak dibaca.', 'contoh' => 'gram', 'info' => true],
        ];
    }

    protected function kolomWajib(): array
    {
        return ['menu', 'bahan', 'takaran'];
    }

    /** Semua baris satu menu disimpan bersama sebagai satu resep. */
    protected function kunciKelompok(array $isi): ?string
    {
        return 'menu:'.mb_strtolower(Str::squish((string) ($isi['menu'] ?? '')));
    }

    protected function barisEkspor(): array
    {
        $hariIni = now()->toDateString();
        $berlaku = Resep::query()
            ->whereDate('berlaku_sejak', '<=', $hariIni)
            ->groupBy('menu_id')
            ->selectRaw('menu_id, MAX(berlaku_sejak) AS tanggal')
            ->pluck('tanggal', 'menu_id');

        $resep = Resep::query()
            ->with('bahanBaku.satuanDasar')
            ->get()
            ->filter(fn (Resep $r) => isset($berlaku[$r->menu_id]) && $r->berlaku_sejak->toDateString() === Carbon::parse($berlaku[$r->menu_id])->toDateString())
            ->groupBy('menu_id');

        return Menu::query()->aktif()->with('kategoriMenu:id,nama,urutan')->get()
            ->sortBy(fn (Menu $m) => [$m->kategoriMenu->urutan, $m->nama])
            ->flatMap(function (Menu $m) use ($resep) {
                $baris = $resep[$m->id] ?? collect();

                if ($baris->isEmpty()) {
                    return [[$m->nama, $m->kategoriMenu->nama, null, null, null]];
                }

                return $baris->sortBy('bahanBaku.nama')->map(fn (Resep $r) => [
                    $m->nama, $m->kategoriMenu->nama, $r->bahanBaku->nama, (float) $r->jumlah,
                    $r->bahanBaku->satuanDasar->nama,
                ])->values()->all();
            })
            ->values()
            ->all();
    }

    public function periksa(array $mentah): array
    {
        $menu = Menu::query()->get()->keyBy(fn (Menu $m) => mb_strtolower($m->nama));
        $bahan = BahanBaku::query()->with('satuanDasar')->get()->keyBy(fn (BahanBaku $b) => mb_strtolower($b->nama));
        $kategori = $this->kumpulanKategori();
        $hariIni = now()->toDateString();

        $kelompok = collect($mentah)->map(fn (array $isi, int $no) => ['no' => $no] + $isi)
            ->groupBy(fn (array $isi) => mb_strtolower(Str::squish((string) $isi['menu'])));

        $hasil = [];

        foreach ($kelompok as $kunci => $daftar) {
            $namaMenu = Str::squish((string) $daftar->first()['menu']);
            $ada = $menu[$kunci] ?? null;
            $namaKategori = (string) $daftar->map(fn ($i) => Str::squish((string) $i['kategori_menu']))->first(fn ($k) => $k !== '');
            $catatanMenu = [];
            $pesanMenu = [];

            if ($kunci === '') {
                $pesanMenu[] = 'Nama menu wajib diisi.';
            } elseif (mb_strlen($namaMenu) > MenuRequest::MAKS['nama']) {
                $pesanMenu[] = 'Nama menu maksimal '.MenuRequest::MAKS['nama'].' huruf.';
            } elseif ($ada && ! $ada->aktif) {
                $pesanMenu[] = "Menu {$ada->nama} sedang nonaktif. Aktifkan dulu.";
            } elseif (! $ada) {
                $catatanMenu[] = 'Menu baru';
                $pesanMenu = [...$pesanMenu, ...($namaKategori === ''
                    ? ['Menu baru: isi Kategori Menu.']
                    : $kategori->periksa($namaKategori, 'Kategori menu', KategoriMenuRequest::MAKS_NAMA, $catatanMenu))];
            }

            $berlaku = $hariIni;
            $adaBahan = $daftar->contains(fn ($i) => filled($i['bahan']) || filled($i['takaran']));

            if ($ada && $adaBahan && $pesanMenu === []) {
                $catatanMenu[] = $ada->resep()->whereDate('berlaku_sejak', $berlaku)->exists()
                    ? 'Mengganti resep yang berlaku '.Carbon::parse($berlaku)->translatedFormat('j M Y')
                    : 'Resep baru berlaku '.Carbon::parse($berlaku)->translatedFormat('j M Y');
            }

            $barisMenu = [];
            $sudah = [];

            foreach ($daftar as $isi) {
                $namaBahan = Str::squish((string) $isi['bahan']);
                $b = $bahan[mb_strtolower($namaBahan)] ?? null;
                $takaran = Angka::baca($isi['takaran']);
                $pesan = [];

                if ($namaBahan === '' && blank($isi['takaran'])) {
                    if ($adaBahan) {
                        $pesan[] = 'Isi bahan dan takarannya, atau hapus baris ini.';
                    }
                } else {
                    if ($namaBahan === '') {
                        $pesan[] = 'Nama bahan wajib diisi.';
                    } elseif (! $b) {
                        $pesan[] = "Bahan {$namaBahan} tidak ada di daftar bahan baku.";
                    } elseif (! $b->aktif) {
                        $pesan[] = "Bahan {$b->nama} sedang nonaktif.";
                    } elseif (isset($sudah[$b->id])) {
                        $pesan[] = "Bahan {$b->nama} sudah ada di baris {$sudah[$b->id]}.";
                    }

                    if (! is_numeric($takaran) || $takaran <= 0 || $takaran > ResepRequest::MAKS_JUMLAH) {
                        $pesan[] = 'Takaran harus angka lebih dari 0.';
                    }
                }

                if ($b) {
                    $sudah[$b->id] ??= $isi['no'];
                }

                $barisMenu[] = [
                    'no'    => $isi['no'],
                    'pesan' => $pesan,
                    'data'  => [
                        'menu'          => $namaMenu,
                        'kategori_menu' => $namaKategori,
                        'bahan'         => $b?->nama ?? $namaBahan,
                        'bahan_baku_id' => $b?->id,
                        'takaran'       => is_numeric($takaran) ? (float) $takaran : null,
                        'satuan'        => $b?->satuanDasar->nama,
                        'berlaku_sejak' => $berlaku,
                    ],
                ];
            }

            $menuSalah = $pesanMenu !== [] || collect($barisMenu)->contains(fn ($r) => $r['pesan'] !== []);

            if ($ada && ! $adaBahan && ! $menuSalah) {
                foreach ($barisMenu as $r) {
                    $hasil[] = ['baris' => $r['no'], 'status' => self::STATUS_DILEWATI, 'data' => $r['data'],
                        'pesan' => ['Menu sudah ada. Tanpa bahan, resepnya tidak diubah.'], 'catatan' => []];
                }

                continue;
            }

            if ($ada && $adaBahan && ! $menuSalah && $this->samaDenganYangBerlaku($ada, $berlaku, $barisMenu)) {
                foreach ($barisMenu as $r) {
                    $hasil[] = ['baris' => $r['no'], 'status' => self::STATUS_DILEWATI, 'data' => $r['data'],
                        'pesan' => ['Resep sama dengan yang berlaku, tidak diubah.'], 'catatan' => []];
                }

                continue;
            }

            foreach ($barisMenu as $i => $r) {
                $pesan = $i === 0 ? [...$pesanMenu, ...$r['pesan']] : $r['pesan'];

                if ($menuSalah && $pesan === []) {
                    $pesan = ['Resep menu ini tidak disimpan karena ada baris lain yang salah.'];
                }

                $hasil[] = [
                    'baris'   => $r['no'],
                    'status'  => $menuSalah ? self::STATUS_SALAH : self::STATUS_SIAP,
                    'data'    => $r['data'],
                    'pesan'   => $pesan,
                    'catatan' => $menuSalah || $i > 0 ? [] : array_values(array_unique($catatanMenu)),
                ];
            }
        }

        return $hasil;
    }

    public function simpan(array $mentah, User $oleh, ?string $namaFile): array
    {
        return DB::transaction(function () use ($mentah, $oleh, $namaFile) {
            $hasil = $this->periksa($mentah);
            $siap = collect($hasil)->where('status', self::STATUS_SIAP)->groupBy(fn ($r) => mb_strtolower($r['data']['menu']));
            $kategori = $this->kumpulanKategori();
            $menuBaru = 0;

            foreach ($siap as $kunci => $daftar) {
                $pertama = $daftar->first()['data'];
                $menu = Menu::query()->whereRaw('lower(nama) = ?', [$kunci])->first();

                if (! $menu) {
                    $menu = Menu::create([
                        'nama'             => $pertama['menu'],
                        'kategori_menu_id' => $kategori->ambil($pertama['kategori_menu'])->id,
                        'aktif'            => true,
                    ]);
                    $menuBaru++;
                }

                $baris = $daftar->pluck('data')->filter(fn ($d) => $d['bahan_baku_id'] !== null)
                    ->map(fn ($d) => ['bahan_baku_id' => $d['bahan_baku_id'], 'jumlah' => $d['takaran']])
                    ->values()->all();

                if ($baris !== []) {
                    $this->resep->simpanVersi($menu, $pertama['berlaku_sejak'], $baris);
                }
            }

            if ($siap->isNotEmpty()) {
                $this->catatLog($oleh, $siap->count(), $namaFile);
            }

            return [
                'disimpan' => $siap->count(),
                'dilewati' => collect($hasil)->where('status', '!=', self::STATUS_SIAP)->count(),
                'catatan'  => array_values(array_filter([
                    $menuBaru ? $menuBaru.' menu baru' : null,
                    $kategori->jumlahBaru() ? $kategori->jumlahBaru().' kategori menu baru' : null,
                ])),
            ];
        });
    }

    public function ringkasan(array $data): array
    {
        return [
            $data['menu'] !== '' ? $data['menu'] : '(tanpa nama menu)',
            $data['bahan'] !== ''
                ? $data['bahan'].' · '.($data['takaran'] !== null ? Angka::tampil($data['takaran']).' '.$data['satuan'] : '?').' per porsi'
                : 'Tanpa resep',
        ];
    }

    /** Isi file sama persis (bahan dan takaran) dengan resep terbaru menu itu, dan tidak ada versi sesudah tanggal berlakunya. */
    private function samaDenganYangBerlaku(Menu $menu, string $berlaku, array $barisMenu): bool
    {
        $terbaru = $menu->versiResep()->first();

        if ($terbaru === null || $terbaru > $berlaku) {
            return false;
        }

        $kunci = fn ($bahanId, $jumlah) => $bahanId.'|'.round((float) $jumlah, 3);
        $lama = $menu->resepVersi($terbaru)->map(fn (Resep $r) => $kunci($r->bahan_baku_id, $r->jumlah))->sort()->values()->all();
        $baru = collect($barisMenu)->filter(fn ($r) => $r['data']['bahan_baku_id'] !== null)
            ->map(fn ($r) => $kunci($r['data']['bahan_baku_id'], $r['data']['takaran']))->sort()->values()->all();

        return $lama === $baru;
    }

    private function kumpulanKategori(): KumpulanMaster
    {
        return new KumpulanMaster(KategoriMenu::class, fn (string $nama) => KategoriMenu::create([
            'nama' => $nama, 'urutan' => KategoriMenu::urutanBerikutnya(), 'aktif' => true,
        ]));
    }
}
