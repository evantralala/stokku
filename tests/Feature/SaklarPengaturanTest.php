<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use App\Services\KontrolSistemService;
use App\Support\DaftarKontrol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Setiap saklar di Pengaturan benar-benar bekerja: hak akses yang dimatikan menutup semua halaman/aksinya
 * (dan yang dinyalakan membukanya lagi), menu yang dikunci tidak bisa ditutup, dan akun yang dinonaktifkan
 * tidak bisa masuk.
 */
class SaklarPengaturanTest extends TestCase
{
    use RefreshDatabase;

    private const PESAN_TUTUP = 'Menu ini sedang tidak tersedia untuk peran Anda. Hubungi Admin Utama bila perlu.';

    private User $adminUtama;

    /** @var array<string, User> */
    private array $akun;

    protected function setUp(): void
    {
        parent::setUp();

        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->akun = [
            User::ROLE_ADMIN_PUSAT  => User::factory()->adminPusat($pusat->id)->create(),
            User::ROLE_ADMIN_CABANG => User::factory()->adminCabang($cabang->id)->create(),
        ];
    }

    /** Route GET tanpa parameter milik satu menu dan aksi, mis. cabang.opname (lihat) atau cabang.opname.catat (catat). */
    private function ruteGet(string $role, string $menu, string $aksi): array
    {
        $awalan = $role === User::ROLE_ADMIN_PUSAT ? 'pusat.' : 'cabang.';

        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with((string) $r->getName(), $awalan))
            ->filter(fn ($r) => $r->parameterNames() === [])
            ->filter(fn ($r) => DaftarKontrol::aksiRute($role, $r->getName(), 'GET') === [$menu, $aksi])
            ->map(fn ($r) => $r->getName())
            ->values()
            ->all();
    }

    private function tertutup($respons): bool
    {
        return $respons->isRedirect() && session('galat') === self::PESAN_TUTUP;
    }

    public function test_setiap_hak_akses_yang_bisa_diatur_benar_benar_mati_lalu_nyala_lagi(): void
    {
        $diperiksa = 0;
        app(KontrolSistemService::class)->aturModul(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS, true, $this->adminUtama);

        foreach (DaftarKontrol::PERAN as $role) {
            foreach (DaftarKontrol::MENU[$role] as $menu => $definisi) {
                foreach (DaftarKontrol::bisaDiatur($definisi) as $aksi) {
                    $rute = $this->ruteGet($role, $menu, $aksi);

                    $this->aturHakAkses($role, $menu, $aksi, false);
                    if ($aksi === DaftarKontrol::AKSI_LIHAT) {
                        $this->aturHakAkses($role, $menu, DaftarKontrol::AKSI_CATAT, false);
                    }

                    foreach ($rute as $nama) {
                        $respons = $this->actingAs($this->akun[$role])->get(route($nama));
                        $this->assertTrue($this->tertutup($respons), "{$nama} harus tertutup saat {$menu}.{$aksi} mati");
                        $diperiksa++;
                    }

                    $this->aturHakAkses($role, $menu, $aksi, true);
                    if ($aksi === DaftarKontrol::AKSI_LIHAT) {
                        $this->aturHakAkses($role, $menu, DaftarKontrol::AKSI_CATAT, true);
                    }

                    foreach ($rute as $nama) {
                        $this->flushSession();
                        $respons = $this->actingAs($this->akun[$role])->get(route($nama));
                        $this->assertFalse($this->tertutup($respons), "{$nama} harus terbuka saat {$menu}.{$aksi} nyala");
                    }
                }
            }
        }

        $this->assertGreaterThan(5, $diperiksa);
    }

    public function test_hak_akses_mati_juga_menolak_simpan_langsung(): void
    {
        $this->aturHakAkses(User::ROLE_ADMIN_CABANG, 'opname', DaftarKontrol::AKSI_CATAT, false);
        $this->aturHakAkses(User::ROLE_ADMIN_CABANG, 'kirim-barang', DaftarKontrol::AKSI_CATAT, false);

        $this->actingAs($this->akun[User::ROLE_ADMIN_CABANG])->post(route('cabang.opname.mulai'))->assertSessionHas('galat', self::PESAN_TUTUP);
        $this->actingAs($this->akun[User::ROLE_ADMIN_CABANG])->post(route('cabang.kirim-barang.simpan'), [])->assertSessionHas('galat', self::PESAN_TUTUP);
    }

    public function test_menu_bergembok_tetap_terbuka_walau_dikirim_mati(): void
    {
        foreach (DaftarKontrol::PERAN as $role) {
            foreach (DaftarKontrol::MENU[$role] as $menu => $definisi) {
                foreach ($definisi['kunci'] as $aksi) {
                    $this->aturHakAkses($role, $menu, $aksi, false);
                    $this->assertTrue(app(KontrolSistemService::class)->bolehPeran($role, $menu, $aksi), "{$role} {$menu}.{$aksi} terkunci");
                }
            }
        }
    }

    public function test_akun_nonaktif_tidak_bisa_masuk_dan_yang_sedang_masuk_dikeluarkan(): void
    {
        $cabang = $this->akun[User::ROLE_ADMIN_CABANG];
        $cabang->forceFill(['password' => 'rahasia123'])->save();

        $this->actingAs($this->adminUtama)->patch(route('admin-utama.kontrol-sistem.akun', $cabang), ['aktif' => 0])->assertSessionHas('sukses');

        $this->actingAs($cabang->fresh())->get(route('cabang.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login'), ['email' => $cabang->email, 'password' => 'rahasia123']);
        $this->assertGuest();

        $this->actingAs($this->adminUtama)->patch(route('admin-utama.kontrol-sistem.akun', $cabang), ['aktif' => 1])->assertSessionHas('sukses');
        auth()->logout();

        $this->post(route('login'), ['email' => $cabang->email, 'password' => 'rahasia123']);
        $this->assertAuthenticatedAs($cabang->fresh());
    }

    public function test_fitur_mati_dan_nyala_mengubah_jadwal_dan_dashboard(): void
    {
        $kontrol = app(KontrolSistemService::class);

        $kontrol->aturModul(DaftarKontrol::MODUL_PERINGATAN_DINI, false, $this->adminUtama);
        $this->actingAs($this->adminUtama)->get(route('admin-utama.dashboard'))->assertDontSee('id="peringatan-dini"', false);
        $kontrol->aturModul(DaftarKontrol::MODUL_PERINGATAN_DINI, true, $this->adminUtama);
        $this->actingAs($this->adminUtama)->get(route('admin-utama.dashboard'))->assertSee('id="peringatan-dini"', false);

        foreach ([DaftarKontrol::MODUL_TOLAK_OTOMATIS => 'persetujuan:tolak-otomatis', DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS => 'permintaan:buat-otomatis --paksa'] as $modul => $perintah) {
            $kontrol->aturModul($modul, false, $this->adminUtama);
            $this->artisan($perintah)->expectsOutputToContain('dimatikan')->assertSuccessful();

            $kontrol->aturModul($modul, true, $this->adminUtama);
            $this->artisan($perintah)->doesntExpectOutputToContain('dimatikan')->assertSuccessful();
        }
    }
}
