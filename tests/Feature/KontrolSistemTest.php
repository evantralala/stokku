<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KontrolSistemTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminCabang;

    protected function setUp(): void
    {
        parent::setUp();

        $cabang = Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->adminUtama = User::factory()->adminUtama()->create(['nama' => 'Utama Satu']);
        $this->adminCabang = User::factory()->adminCabang($cabang->id)->create(['nama' => 'Rina Kusuma', 'password' => 'rahasia123']);
    }

    public function test_halaman_pengaturan_hanya_untuk_admin_utama(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSee('Rina Kusuma')
            ->assertSee('role="switch"', false)
            ->assertSee('data-popup', false);

        $this->actingAs($this->adminCabang)
            ->get(route('admin-utama.pengaturan'))
            ->assertForbidden();
    }

    public function test_pengaturan_hanya_berisi_bagian_sehari_hari(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.kontrol-sistem'))
            ->assertRedirect(route('admin-utama.pengaturan'));

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSeeInOrder(['id="umum"', 'id="identitas"', 'id="fitur"', 'id="akun"', 'id="akses"', 'id="periode"'], false)
            ->assertDontSee('id="teknisi"', false)
            ->assertDontSee('Untuk Teknisi')
            ->assertDontSee('Mode Perbaikan')
            ->assertDontSee('Cadangan Data')
            ->assertDontSee('Reset Data')
            ->assertDontSee('Log Perubahan')
            ->assertDontSee('schedule:run');
    }

    public function test_fitur_teknisi_sudah_dihapus_seluruhnya(): void
    {
        foreach (['kontrol-sistem.perbaikan', 'kontrol-sistem.log', 'kontrol-sistem.log.unduh', 'kontrol-sistem.cadangan', 'kontrol-sistem.cadangan.unduh',
            'kontrol-sistem.foto.unduh', 'kontrol-sistem.reset', 'kontrol-sistem.reset.simpan'] as $nama) {
            $this->assertFalse(Route::has('admin-utama.'.$nama), $nama);
        }

        foreach (['perbaikan', 'log', 'reset-data', 'foto/unduh'] as $alamat) {
            $this->actingAs($this->adminUtama)->get('/admin-utama/kontrol-sistem/'.$alamat)->assertNotFound();
        }

        $perintah = array_keys(Artisan::all());
        foreach (['cadangan:buat', 'jadwal:detak', 'data:reset'] as $nama) {
            $this->assertNotContains($nama, $perintah);
        }

        $this->actingAs($this->adminUtama)->get(route('admin-utama.dashboard'))->assertOk()->assertDontSee('Jadwal otomatis tidak jalan');
        $this->get(route('admin-utama.riwayat', ['jenis' => 'pengaturan']))->assertOk();
    }

    public function test_akun_bisa_dimatikan_dan_dinyalakan_dari_kontrol_sistem(): void
    {
        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.kontrol-sistem.akun', $this->adminCabang), ['aktif' => 0])
            ->assertRedirect(route('admin-utama.pengaturan'))
            ->assertSessionHas('sukses');

        $this->assertFalse($this->adminCabang->fresh()->aktif);

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.kontrol-sistem.akun', $this->adminCabang), ['aktif' => 1])
            ->assertSessionHas('sukses');

        $this->assertTrue($this->adminCabang->fresh()->aktif);
        $this->assertSame(['akun.mati', 'akun.nyala'], LogPerubahan::orderBy('id')->pluck('aksi')->all());
        $this->assertSame($this->adminCabang->id, LogPerubahan::orderBy('id')->first()->objek_id);
    }

    public function test_akun_sendiri_dan_admin_utama_terakhir_tidak_bisa_dimatikan(): void
    {
        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.kontrol-sistem.akun', $this->adminUtama), ['aktif' => 0])
            ->assertSessionHas('galat', 'Anda tidak bisa menonaktifkan akun sendiri.');

        $this->assertTrue($this->adminUtama->fresh()->aktif);
        $this->assertSame(0, LogPerubahan::count());

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.kontrol-sistem.akun', $this->adminCabang), ['aktif' => 'bukan'])
            ->assertSessionHasErrors('aktif');
    }
}
