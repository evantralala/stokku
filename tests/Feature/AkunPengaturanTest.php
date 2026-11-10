<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\User;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AkunPengaturanTest extends TestCase
{
    use RefreshDatabase;

    public function test_semua_peran_bisa_membuka_akun_saya(): void
    {
        $cabang = Cabang::create(['kode' => 'C1', 'nama' => 'Cabang', 'jenis' => Cabang::JENIS_CABANG]);
        $admin = User::factory()->adminCabang($cabang->id)->create();

        $this->actingAs($admin)
            ->get(route('akun'))
            ->assertOk()
            ->assertSee('Ganti Password')
            ->assertSee('Cabang');
    }

    public function test_ganti_password_sendiri_butuh_password_lama_yang_benar(): void
    {
        $adminUtama = User::factory()->adminUtama()->create(['password' => 'passwordlama']);

        $this->actingAs($adminUtama)
            ->put(route('akun.password'), [
                'password_lama' => 'salah', 'password_baru' => 'passwordbaru', 'password_baru_confirmation' => 'passwordbaru',
            ])
            ->assertSessionHasErrors('password_lama');

        $this->actingAs($adminUtama)
            ->put(route('akun.password'), [
                'password_lama' => 'passwordlama', 'password_baru' => 'passwordbaru', 'password_baru_confirmation' => 'passwordbaru',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('akun'));

        $this->assertTrue(Hash::check('passwordbaru', $adminUtama->fresh()->password));
        $this->assertAuthenticatedAs($adminUtama);
    }

    public function test_halaman_pengaturan_dibangun_dari_tabel(): void
    {
        $this->seed(PengaturanSeeder::class);
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)
            ->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSeeInOrder(['Batas Waktu Persetujuan', 'Jam Permintaan Otomatis', 'Toleransi Selisih Bawaan', 'Ambang Stok Menipis'])
            ->assertSee('type="time"', false);
    }

    public function test_nilai_di_luar_batas_ditolak_dan_nilai_benar_tersimpan(): void
    {
        $this->seed(PengaturanSeeder::class);
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)
            ->put(route('admin-utama.pengaturan.simpan'), ['nilai' => [
                'batas_jam_persetujuan' => '500', 'jam_permintaan_otomatis' => '25:00',
                'toleransi_default' => '150', 'ambang_stok_menipis' => '20', 'persen_cadangan_minta' => '20',
            ]])
            ->assertSessionHasErrors(['nilai.batas_jam_persetujuan', 'nilai.jam_permintaan_otomatis', 'nilai.toleransi_default']);

        $this->actingAs($adminUtama)
            ->put(route('admin-utama.pengaturan.simpan'), ['nilai' => [
                'batas_jam_persetujuan' => '12', 'jam_permintaan_otomatis' => '05:30', 'jam_ganti_hari_penjualan' => '06:00',
                'toleransi_default' => '2,5', 'ambang_stok_menipis' => '20', 'persen_cadangan_minta' => '20',
            ]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses', '3 pengaturan berhasil diperbarui.');

        $this->assertSame('12', Pengaturan::ambil('batas_jam_persetujuan'));
        $this->assertSame('05:30', Pengaturan::ambil('jam_permintaan_otomatis'));
        $this->assertSame('2.5', Pengaturan::ambil('toleransi_default'));
    }

    public function test_admin_tidak_bisa_membuka_pengaturan_dan_pengguna(): void
    {
        $cabang = Cabang::create(['kode' => 'C1', 'nama' => 'Cabang', 'jenis' => Cabang::JENIS_CABANG]);
        $admin = User::factory()->adminCabang($cabang->id)->create();

        $this->actingAs($admin)->get(route('admin-utama.pengaturan'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin-utama.pengguna'))->assertForbidden();
    }
}
