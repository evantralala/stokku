<?php

namespace Tests\Feature;

use App\Models\Satuan;
use App\Models\User;
use App\Support\VersiData;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pembaruan halaman dalam hitungan detik (penanda VersiData) dan aplikasi yang bisa dipasang di HP (PWA). */
class RealtimePwaTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PengaturanSeeder::class);
        $this->adminUtama = User::factory()->adminUtama()->create(['password' => 'rahasia-utama']);
    }

    private function versi(): string
    {
        return $this->getJson(route('segar.versi'))->assertOk()->json('versi');
    }

    public function test_penanda_versi_ringan_tanpa_sesi_dan_tidak_disimpan_browser(): void
    {
        $respons = $this->getJson(route('segar.versi'))->assertOk()->assertJsonStructure(['versi']);

        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));
        $this->assertSame([], $respons->headers->getCookies());
        $this->assertSame($respons->json('versi'), $this->versi());
    }

    public function test_penanda_berganti_saat_data_disimpan_dan_tidak_saat_dibaca_atau_gagal(): void
    {
        $awal = $this->versi();

        $this->actingAs($this->adminUtama)->get(route('admin-utama.satuan'))->assertOk()
            ->assertSee('<meta name="versi-data" content="'.$awal.'">', false)
            ->assertSee('<meta name="url-versi" content="'.route('segar.versi').'">', false);
        $this->assertSame($awal, $this->versi());

        $this->post(route('admin-utama.satuan.simpan'), ['nama' => ''])->assertSessionHasErrors('nama');
        $this->assertSame($awal, $this->versi());

        $this->post(route('admin-utama.satuan.simpan'), ['nama' => 'liter'])->assertSessionHasNoErrors();
        $this->assertSame(1, Satuan::count());
        $kedua = $this->versi();
        $this->assertNotSame($awal, $kedua);

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $this->adminUtama->email, 'password' => 'rahasia-utama']);
        $this->assertSame($kedua, $this->versi());
    }

    public function test_perintah_terjadwal_tanpa_perubahan_tidak_mengganti_penanda(): void
    {
        $awal = VersiData::sekarang();

        $this->artisan('persetujuan:tolak-otomatis')->assertSuccessful();

        $this->assertSame($awal, VersiData::sekarang());
    }

    public function test_manifest_ikon_service_worker_dan_halaman_offline_tersedia(): void
    {
        $manifest = $this->get(route('manifest'))->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();

        $this->assertSame('Stokku', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ($manifest['icons'] as $ikon) {
            [$lebar, $tinggi] = getimagesize(public_path(ltrim(parse_url($ikon['src'], PHP_URL_PATH), '/')));
            $this->assertSame($ikon['sizes'], $lebar.'x'.$tinggi, $ikon['src']);
        }

        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString('Tidak ada koneksi internet', (string) file_get_contents(public_path('offline.html')));
        $this->assertFileExists(public_path('img/ikon/apple-touch-icon.png'));
    }

    public function test_halaman_memuat_manifest_dan_akun_menampilkan_cara_pasang(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('<link rel="manifest" href="'.route('manifest').'">', false)
            ->assertSee('<meta name="service-worker" content="'.asset('sw.js').'">', false);

        $this->actingAs($this->adminUtama)->get(route('akun'))->assertOk()
            ->assertSee('<link rel="manifest"', false)
            ->assertSee('Pasang di HP')
            ->assertSee('data-pasang-aplikasi hidden', false);
    }
}
