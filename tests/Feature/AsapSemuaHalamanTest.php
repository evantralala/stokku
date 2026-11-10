<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminUtama\LaporanController;
use App\Services\Impor\DaftarExcelMaster;
use App\Support\DaftarKontrol;
use App\Support\DaftarRiwayat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\DataAlurLengkap;
use Tests\TestCase;

/**
 * Uji asap: setiap halaman GET dibuka oleh setiap peran yang berhak, dengan data hasil alur lengkap
 * (permintaan, kiriman, terima, kirim antar cabang, barang masuk, barang rusak, penjualan, tambah stok, opname).
 * Tidak boleh ada halaman yang gagal (kode 500 atau pengecualian).
 */
class AsapSemuaHalamanTest extends TestCase
{
    use DataAlurLengkap;
    use RefreshDatabase;

    public function test_semua_halaman_terbuka_tanpa_galat_untuk_setiap_peran(): void
    {
        ['au' => $au, 'pusat' => $pusat, 'cab' => $cab, 'cab2' => $cab2, 'gp' => $gp, 'jbr' => $jbr, 'kts' => $kts, 'beras' => $beras] = $this->siapkanData();

        $nilai = [
            'master' => array_keys(DaftarExcelMaster::KELAS),
            'jenis'  => array_keys(LaporanController::JENIS),
            'peran'  => array_keys(DaftarKontrol::PERAN),
        ];
        $model = fn (string $p) => match ($p) {
            'bahanBaku' => $beras->id,
            'lokasi' => $jbr->id,
            'pengguna' => $cab->id,
            default => (function () use ($p) {
                $kelas = 'App\\Models\\'.($p === 'penjualan' ? 'PenjualanHarian' : Str::studly($p));
                return class_exists($kelas) ? $kelas::query()->value((new $kelas)->getKeyName()) : null;
            })(),
        };

        $hasil = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods()) || ! $r->getName() || in_array($r->getName(), ['login', 'logout'], true)
                || Str::startsWith($r->uri(), ['_', 'storage', 'foto', 'up', 'sanctum'])) {
                continue;
            }
            $nama = $r->getName();
            $peran = match (true) {
                str_starts_with($nama, 'admin-utama.') => ['au' => $au],
                str_starts_with($nama, 'pusat.') => ['pusat' => $pusat],
                str_starts_with($nama, 'cabang.') => ['cab' => $cab, 'cab2' => $cab2],
                default => ['au' => $au, 'pusat' => $pusat, 'cab' => $cab],
            };
            preg_match_all('/\{(\w+)\??\}/', $r->uri(), $m);
            $kombinasi = [[]];
            foreach ($m[1] as $p) {
                $isi = $nilai[$p] ?? [$model($p)];
                $baru = [];
                foreach ($kombinasi as $k) {
                    foreach ($isi as $v) {
                        $baru[] = $k + [$p => $v];
                    }
                }
                $kombinasi = $baru;
            }
            foreach ($peran as $label => $u) {
                foreach ($kombinasi as $param) {
                    if (in_array(null, $param, true)) {
                        $hasil[] = "SKIP $label $nama ".json_encode($param);
                        continue;
                    }
                    try {
                        $url = route($nama, $param);
                        $kode = $this->actingAs($u)->get($url)->getStatusCode();
                        $hasil[] = "$kode $label $nama ".json_encode($param);
                        if (in_array($nama, ['admin-utama.stok', 'admin-utama.stok.isi', 'admin-utama.stok.mutasi'])) {
                            foreach ([$gp, $kts] as $l) {
                                $hasil[] = $this->get(route($nama, $param + ['lokasi' => $l->id]))->getStatusCode()." $label $nama lokasi={$l->kode}";
                            }
                        }
                    } catch (\Throwable $e) {
                        $hasil[] = "EXC $label $nama ".json_encode($param).' '.get_class($e).': '.Str::limit($e->getMessage(), 300);
                    }
                }
            }
        }
        foreach (array_keys(DaftarRiwayat::JENIS) as $jenis) {
            $hasil[] = $this->actingAs($au)->get(route('admin-utama.riwayat', ['jenis' => $jenis]))->getStatusCode()." au admin-utama.riwayat {$jenis}";
        }

        $gagal = array_values(array_filter($hasil, fn (string $h) => str_starts_with($h, 'EXC') || str_starts_with($h, '5')));
        $this->assertSame([], $gagal, implode("\n", $gagal));
        $this->assertGreaterThan(200, count($hasil));
    }

    /**
     * Semua tautan di setiap halaman (menu, tombol, baris daftar) ditelusuri per peran mulai dari dashboard.
     * Tautan internal tidak boleh rusak (404/500) dan gambar di /img harus ada.
     */
    public function test_semua_tautan_di_halaman_tidak_rusak_untuk_setiap_peran(): void
    {
        ['au' => $au, 'pusat' => $pusat, 'cab' => $cab, 'cab2' => $cab2] = $this->siapkanData();
        $akar = rtrim(url('/'), '/');
        $rusak = [];
        $jumlah = 0;

        foreach (['au' => $au, 'pusat' => $pusat, 'cab' => $cab, 'cab2' => $cab2] as $label => $u) {
            $antre = [route($u->ruteDashboard(), [], false)];
            $asal = [];
            $sudah = [];

            while ($antre !== [] && count($sudah) < 400) {
                $jalur = array_shift($antre);
                if (isset($sudah[$jalur])) {
                    continue;
                }
                $sudah[$jalur] = true;
                // Di aplikasi sungguhan setiap request punya kontainer baru; di tes kontainer dipakai ulang.
                $this->app->forgetScopedInstances();
                $respons = $this->actingAs($u)->get($jalur);
                $kode = $respons->getStatusCode();
                $jumlah++;

                if ($kode >= 400) {
                    $rusak[] = "$kode $label $jalur (dari ".($asal[$jalur] ?? '-').')';
                    continue;
                }
                if ($kode >= 300 || ! str_contains((string) $respons->headers->get('Content-Type'), 'text/html')) {
                    continue;
                }

                preg_match_all('/\b(?:href|src)="([^"#]+)/', (string) $respons->getContent(), $m);
                foreach (array_unique($m[1]) as $tautan) {
                    $tautan = html_entity_decode($tautan);
                    if (str_starts_with($tautan, $akar)) {
                        $tautan = substr($tautan, strlen($akar)) ?: '/';
                    }
                    if (! str_starts_with($tautan, '/') || str_starts_with($tautan, '//')) {
                        continue;
                    }
                    if (is_file(public_path(ltrim((string) parse_url($tautan, PHP_URL_PATH), '/')))) {
                        continue;
                    }
                    if (str_starts_with($tautan, '/img/')) {
                        if (! is_file(public_path(parse_url($tautan, PHP_URL_PATH)))) {
                            $rusak[] = "GAMBAR $label $tautan (di $jalur)";
                        }
                        continue;
                    }
                    if (preg_match('#^/(build|storage|foto|logout)\b|/(unduh|pdf|surat-jalan)(\?|$)|/excel/unduh#', $tautan)) {
                        continue;
                    }
                    if (! isset($sudah[$tautan])) {
                        $antre[] = $tautan;
                        $asal[$tautan] ??= $jalur;
                    }
                }
            }
        }

        $this->assertSame([], $rusak, implode("\n", $rusak));
        $this->assertGreaterThan(150, $jumlah);
    }
}
