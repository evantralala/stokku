<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AksesRoleTest extends TestCase
{
    use RefreshDatabase;

    private function buatLokasi(string $jenis): Cabang
    {
        return Cabang::create([
            'kode' => $jenis === Cabang::JENIS_PUSAT ? 'P1' : 'C1',
            'nama' => $jenis === Cabang::JENIS_PUSAT ? 'Lokasi Pusat' : 'Lokasi Cabang',
            'jenis' => $jenis,
        ]);
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('admin-utama.dashboard'))->assertRedirect(route('login'));
    }

    public function test_login_diarahkan_ke_dashboard_sesuai_role(): void
    {
        $adminUtama = User::factory()->adminUtama()->create(['password' => 'rahasia123']);

        $this->post(route('login.store'), ['email' => $adminUtama->email, 'password' => 'rahasia123'])
            ->assertRedirect(route('admin-utama.dashboard'));
    }

    public function test_admin_utama_bisa_membuka_halaman_admin_utama(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)->get(route('admin-utama.dashboard'))->assertOk();
        $this->actingAs($adminUtama)->get(route('admin-utama.satuan'))->assertOk();
    }

    public function test_admin_pusat_dan_cabang_bisa_membuka_dashboard_masing_masing(): void
    {
        $pusat = User::factory()->adminPusat($this->buatLokasi(Cabang::JENIS_PUSAT)->id)->create();
        $cabang = User::factory()->adminCabang($this->buatLokasi(Cabang::JENIS_CABANG)->id)->create();

        $this->actingAs($pusat)->get(route('pusat.dashboard'))->assertOk();
        $this->actingAs($cabang)->get(route('cabang.dashboard'))->assertOk();
    }

    public function test_admin_cabang_tidak_bisa_membuka_halaman_admin_utama(): void
    {
        $admin = User::factory()->adminCabang($this->buatLokasi(Cabang::JENIS_CABANG)->id)->create();

        $this->actingAs($admin)->get(route('admin-utama.satuan'))->assertForbidden();
        $this->actingAs($admin)->get(route('pusat.dashboard'))->assertForbidden();
    }

    public function test_akun_yang_dinonaktifkan_langsung_dikeluarkan(): void
    {
        $adminUtama = User::factory()->adminUtama()->nonaktif()->create();

        $this->actingAs($adminUtama)->get(route('admin-utama.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_utama_login_masuk_ke_alamat_admin_utama(): void
    {
        $adminUtama = User::factory()->adminUtama()->create(['password' => 'rahasia123']);

        $this->post(route('login.store'), ['email' => $adminUtama->email, 'password' => 'rahasia123'])
            ->assertRedirect('/admin-utama/dashboard');

        $this->get('/admin-utama/dashboard')->assertOk();
        $this->assertSame('admin_utama', $adminUtama->fresh()->role);
        $this->assertSame('Admin Utama', $adminUtama->fresh()->label_role);
    }

    public function test_alamat_lama_owner_sudah_tidak_ada(): void
    {
        $this->get('/owner/persetujuan')->assertNotFound();
        $this->get('/owner')->assertNotFound();
    }
}
