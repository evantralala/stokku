<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminUtama\LaporanController;
use App\Services\Impor\DaftarExcelMaster;
use App\Support\DaftarKontrol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\DataAlurLengkap;
use Tests\TestCase;

/**
 * Uji iseng: meniru pengguna yang mencoba-coba. Setiap form (POST/PUT/PATCH/DELETE) dikirimi isian kosong,
 * huruf di kolom angka, angka minus/raksasa, daftar di kolom tunggal, teks sangat panjang, dan id yang tidak ada;
 * setiap halaman GET dibuka dengan alamat yang diutak-atik. Tidak boleh ada galat server (500) atau pengecualian:
 * yang salah isi harus kembali dengan pesan, yang tidak ada harus 404.
 */
class UjiIsengTest extends TestCase
{
    use DataAlurLengkap;
    use RefreshDatabase;

    private const ID_TIDAK_ADA = 999999;

    /** Nama isian yang dipakai di semua FormRequest, mis. baris.*.qty. */
    private function namaIsian(): array
    {
        $nama = [];
        foreach (glob(app_path('Http/Requests/{,*/,*/*/}*.php'), GLOB_BRACE) as $berkas) {
            preg_match_all("/'([a-z_]+(?:\\.(?:\\*|[a-z_]+))*)'\\s*=>\\s*(?:\\[|'|\"|\\\$|self::|Rule::|array_)/", (string) file_get_contents($berkas), $m);
            array_push($nama, ...$m[1]);
        }

        return array_values(array_unique(array_merge($nama, [
            'tanggal', 'dari', 'sampai', 'baris', 'bahan', 'porsi', 'qty', 'jumlah', 'file', 'kunci', 'tebakan', 'aktif', 'nyala', 'akses',
            'nilai', 'konfirmasi', 'alasan', 'keterangan', 'stok_lama', 'stok_baru', 'nama', 'kode', 'email', 'password', 'lokasi',
        ])));
    }

    /** Isian bentuk aneh: $nilai dipasang di setiap nama, nama bertitik menjadi bersarang. */
    private function isian(array $nama, mixed $nilai): array
    {
        $isi = [];
        foreach ($nama as $n) {
            $jalur = str_replace('*', '0', $n);
            data_set($isi, $jalur, $nilai);
        }

        return $isi;
    }

    private function nilaiParameter(string $p, array $data): array
    {
        $ada = match ($p) {
            'master' => array_keys(DaftarExcelMaster::KELAS),
            'jenis'  => array_keys(LaporanController::JENIS),
            'peran'  => array_keys(DaftarKontrol::PERAN),
            'modul'  => array_keys(DaftarKontrol::MODUL),
            'bahanBaku' => [$data['beras']->id],
            'lokasi' => [$data['jbr']->id],
            'pengguna' => [$data['cab']->id],
            default => (function () use ($p) {
                $kelas = 'App\\Models\\'.($p === 'penjualan' ? 'PenjualanHarian' : Str::studly($p));

                return class_exists($kelas) ? array_filter([$kelas::query()->value((new $kelas)->getKeyName())]) : [];
            })(),
        };

        return in_array($p, ['master', 'jenis', 'peran', 'modul'], true) ? $ada : [...$ada, self::ID_TIDAK_ADA];
    }

    /** @return array<string, \App\Models\User> */
    private function peranUntuk(string $nama, array $data): array
    {
        return match (true) {
            str_starts_with($nama, 'admin-utama.') => ['au' => $data['au'], 'cab' => $data['cab'], 'pusat' => $data['pusat']],
            str_starts_with($nama, 'pusat.') => ['pusat' => $data['pusat'], 'cab' => $data['cab']],
            str_starts_with($nama, 'cabang.') => ['cab' => $data['cab'], 'cab2' => $data['cab2'], 'pusat' => $data['pusat']],
            default => ['au' => $data['au'], 'pusat' => $data['pusat'], 'cab' => $data['cab']],
        };
    }

    /** Admin Pusat diberi semua menu Admin Utama yang bisa dibagikan, supaya halaman itu juga diuji dengan perannya. */
    private function bukaSemuaMenuUtama(): void
    {
        foreach (DaftarKontrol::menuPeran(\App\Models\User::ROLE_ADMIN_PUSAT) as $menu => $definisi) {
            if (isset($definisi['awalan'])) {
                \App\Models\KontrolSistem::query()->updateOrCreate(['kunci' => "akses.admin_pusat.{$menu}.lihat"], ['nilai' => '1']);
            }
        }
    }

    /** @return list<array<string, mixed>> semua kombinasi parameter route */
    private function kombinasi($route, array $data): array
    {
        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
        $hasil = [[]];
        foreach ($m[1] as $p) {
            $baru = [];
            foreach ($this->nilaiParameter($p, $data) as $v) {
                foreach ($hasil as $k) {
                    $baru[] = $k + [$p => $v];
                }
            }
            $hasil = $baru;
        }

        return $hasil;
    }

    private function catat(array &$gagal, int|string $kode, string $keterangan): void
    {
        if ($kode === 'EXC' || (is_int($kode) && $kode >= 500)) {
            $gagal[] = "$kode $keterangan";
        }
    }

    /** Ringkas: satu baris per tempat galat (berkas:baris), dengan contoh pemicunya. */
    private function ringkas(array $gagal): string
    {
        $per = [];
        foreach ($gagal as $g) {
            $tempat = preg_match('/@ (\S+)$/', $g, $m) ? $m[1] : $g;
            $per[$tempat] ??= [0, $g];
            $per[$tempat][0]++;
        }

        return implode("\n", array_map(fn ($v) => $v[0].'x  '.$v[1], $per));
    }

    public function test_semua_form_tahan_isian_aneh_dari_setiap_peran(): void
    {
        $data = $this->siapkanData();
        $this->bukaSemuaMenuUtama();
        $nama = $this->namaIsian();
        $panjang = str_repeat('Ab<script>x</script> ', 400);
        $bentuk = [
            'kosong'   => [],
            'huruf'    => $this->isian($nama, 'abc'),
            'minus'    => $this->isian($nama, '-5'),
            'raksasa'  => $this->isian($nama, '99999999999999999999999'),
            'koma'     => $this->isian($nama, '1,2,3.4.5'),
            'daftar'   => $this->isian($nama, ['a' => ['b' => 'c']]),
            'panjang'  => $this->isian($nama, $panjang),
            'id-hilang' => $this->isian($nama, (string) self::ID_TIDAK_ADA),
        ];

        $gagal = [];
        $jumlah = 0;
        foreach (Route::getRoutes() as $r) {
            $metode = array_values(array_diff($r->methods(), ['GET', 'HEAD']));
            if ($metode === [] || ! $r->getName() || in_array($r->getName(), ['login.store', 'logout'], true)) {
                continue;
            }
            foreach ($this->kombinasi($r, $data) as $param) {
                foreach ($this->peranUntuk($r->getName(), $data) as $label => $u) {
                    foreach ($bentuk as $jenis => $isi) {
                        $ket = "{$metode[0]} $label {$r->getName()} ".json_encode($param)." $jenis";
                        try {
                            $this->app->forgetScopedInstances();
                            $respons = $this->actingAs($u)->call($metode[0], route($r->getName(), $param), $isi);
                            $kode = $respons->getStatusCode();
                            if ($kode >= 500 && $respons->exception) {
                                $ket .= ' '.class_basename($respons->exception).': '.Str::limit($respons->exception->getMessage(), 160)
                                    .' @ '.str_replace(base_path().'/', '', $respons->exception->getFile()).':'.$respons->exception->getLine();
                            }
                        } catch (\Throwable $e) {
                            $kode = 'EXC';
                            $ket .= ' '.get_class($e).': '.Str::limit($e->getMessage(), 200);
                        }
                        $this->catat($gagal, $kode, $ket);
                        $jumlah++;
                    }
                }
            }
        }

        $this->assertSame([], $gagal, $this->ringkas($gagal)."\n(".count($gagal).' gagal)');
        $this->assertGreaterThan(500, $jumlah);
    }

    public function test_semua_halaman_tahan_alamat_yang_diutak_atik(): void
    {
        $data = $this->siapkanData();
        $this->bukaSemuaMenuUtama();
        $kueri = [
            ['lokasi' => 'abc', 'dari' => 'bukan-tanggal', 'sampai' => '2026-13-45', 'tanggal' => '31/02/2026'],
            ['lokasi' => self::ID_TIDAK_ADA, 'kategori' => '-1', 'lampu' => 'ungu', 'status' => 'zzz', 'page' => '-3'],
            ['lokasi' => ['x'], 'cari' => ['a' => 'b'], 'bahan' => 'abc', 'objek' => 'x', 'periode' => '../../etc'],
            ['bahan' => ['abc', '-1', self::ID_TIDAK_ADA], 'dari' => '2099-01-01', 'sampai' => '1990-01-01', 'cari' => str_repeat('%_\'"', 300)],
            ['jenis' => '../../x', 'kategori' => ['1'], 'penjualan' => 'abc', 'hari' => '-9999', 'per' => '0'],
        ];

        $gagal = [];
        $jumlah = 0;
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true) || ! $r->getName() || in_array($r->getName(), ['login', 'logout'], true)
                || Str::startsWith($r->uri(), ['_', 'storage', 'up', 'sanctum'])) {
                continue;
            }
            foreach ($this->kombinasi($r, $data) as $param) {
                foreach ($this->peranUntuk($r->getName(), $data) as $label => $u) {
                    foreach ([[], ...$kueri] as $q) {
                        $ket = "GET $label {$r->getName()} ".json_encode($param).' '.json_encode($q);
                        try {
                            $this->app->forgetScopedInstances();
                            $respons = $this->actingAs($u)->get(route($r->getName(), $param).'?'.http_build_query($q));
                            $kode = $respons->getStatusCode();
                            if ($kode >= 500 && $respons->exception) {
                                $ket .= ' '.class_basename($respons->exception).': '.Str::limit($respons->exception->getMessage(), 160)
                                    .' @ '.str_replace(base_path().'/', '', $respons->exception->getFile()).':'.$respons->exception->getLine();
                            }
                        } catch (\Throwable $e) {
                            $kode = 'EXC';
                            $ket .= ' '.get_class($e).': '.Str::limit($e->getMessage(), 200);
                        }
                        $this->catat($gagal, $kode, $ket);
                        $jumlah++;
                    }
                }
            }
        }

        $this->assertSame([], $gagal, $this->ringkas($gagal)."\n(".count($gagal).' gagal)');
        $this->assertGreaterThan(500, $jumlah);
    }
}
