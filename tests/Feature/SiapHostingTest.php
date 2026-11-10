<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pengaman sebelum hosting: HTTPS dipaksa bila diminta, akun Admin Utama pertama tanpa sandi bawaan. */
class SiapHostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_dialihkan_ke_https_bila_paksa_https_menyala(): void
    {
        config(['stokku.paksa_https' => true]);

        $this->get('http://localhost/login')->assertStatus(301)->assertRedirect('https://localhost/login');
        $this->get('https://localhost/login')->assertOk();
    }

    public function test_tanpa_paksa_https_http_tetap_dibuka(): void
    {
        $this->get('http://localhost/login')->assertOk();
    }

    public function test_tanpa_sandi_di_env_halaman_masuk_meminta_sandi_dan_tidak_membuat_akun(): void
    {
        config(['stokku.admin_utama_awal.password' => null]);

        $this->get(route('login'))->assertOk()->assertSee('Belum ada akun Admin Utama');
        $this->assertSame(0, User::count());
        $this->assertNull(config('stokku.admin_utama_awal.password'));
    }

    public function test_setelah_impor_sql_akun_admin_utama_dibuat_dari_env_saat_halaman_masuk_dibuka(): void
    {
        config(['stokku.admin_utama_awal' => ['nama' => 'Bos', 'email' => 'bos@stokku.test', 'password' => 'rahasia-kuat-1']]);

        $this->get(route('login'))->assertOk()->assertSee('Akun Admin Utama dibuat: bos@stokku.test');
        $this->post(route('login.store'), ['email' => 'bos@stokku.test', 'password' => 'rahasia-kuat-1'])->assertRedirect();
        $this->assertAuthenticated();

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN_UTAMA)->count());
    }

    public function test_riwayat_tanpa_jenis_kembali_ke_dashboard(): void
    {
        $admin = User::factory()->adminUtama()->create();

        $this->actingAs($admin)->get(route('admin-utama.riwayat'))->assertRedirect(route('admin-utama.dashboard'));
    }
}
