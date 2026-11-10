<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PenggunaTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
    }

    private function lokasi(string $jenis, string $nama, bool $aktif = true): Cabang
    {
        return Cabang::create([
            'kode' => strtoupper(substr(md5($nama), 0, 5)), 'nama' => $nama,
            'jenis' => $jenis, 'aktif' => $aktif,
        ]);
    }

    private function dataBaru(array $ubah = []): array
    {
        return $ubah + [
            'nama'                  => 'Fitri Nuraini',
            'email'                 => 'Fitri@Contoh.com ',
            'role'                  => User::ROLE_ADMIN_CABANG,
            'password'              => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ];
    }

    public function test_admin_cabang_wajib_memilih_cabang_dan_email_dirapikan(): void
    {
        $cabang = $this->lokasi(Cabang::JENIS_CABANG, 'Cabang Jati');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru())
            ->assertSessionHasErrors('cabang_id');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru(['cabang_id' => $cabang->id]))
            ->assertSessionHasNoErrors();

        $baru = User::firstWhere('email', 'fitri@contoh.com');
        $this->assertSame($cabang->id, $baru->cabang_id);
        $this->assertTrue(Hash::check('rahasia123', $baru->password));
    }

    public function test_admin_pusat_otomatis_di_gudang_pusat(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru(['role' => User::ROLE_ADMIN_PUSAT]))
            ->assertSessionHasErrors('role');

        $pusat = $this->lokasi(Cabang::JENIS_PUSAT, 'Gudang Pusat');
        $cabang = $this->lokasi(Cabang::JENIS_CABANG, 'Cabang Jati');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru(['role' => User::ROLE_ADMIN_PUSAT, 'cabang_id' => $cabang->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($pusat->id, User::firstWhere('email', 'fitri@contoh.com')->cabang_id);
    }

    public function test_admin_utama_tidak_punya_lokasi_dan_cabang_nonaktif_ditolak(): void
    {
        $tutup = $this->lokasi(Cabang::JENIS_CABANG, 'Cabang Tutup', aktif: false);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru(['cabang_id' => $tutup->id]))
            ->assertSessionHasErrors('cabang_id');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru(['role' => User::ROLE_ADMIN_UTAMA, 'cabang_id' => $tutup->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull(User::firstWhere('email', 'fitri@contoh.com')->cabang_id);
    }

    public function test_password_wajib_minimal_delapan_dan_sama(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), $this->dataBaru(['role' => User::ROLE_ADMIN_UTAMA, 'password' => 'pendek', 'password_confirmation' => 'beda']))
            ->assertSessionHasErrors('password');
    }

    public function test_admin_utama_tidak_bisa_menonaktifkan_menghapus_atau_mengubah_peran_sendiri(): void
    {
        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.pengguna.aktif', $this->adminUtama), ['aktif' => 0])
            ->assertSessionHas('galat');

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.pengguna.hapus', $this->adminUtama))
            ->assertSessionHas('galat');

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.pengguna.perbarui', $this->adminUtama), [
                'nama' => $this->adminUtama->nama, 'email' => $this->adminUtama->email, 'role' => User::ROLE_ADMIN_CABANG,
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue($this->adminUtama->fresh()->aktif);
        $this->assertTrue($this->adminUtama->fresh()->isAdminUtama());
    }

    public function test_atur_ulang_password_mengeluarkan_pengguna_dari_semua_sesi(): void
    {
        $cabang = $this->lokasi(Cabang::JENIS_CABANG, 'Cabang Jati');
        $admin = User::factory()->adminCabang($cabang->id)->create();

        DB::table('sessions')->insert([
            'id' => 'sesi-lama', 'user_id' => $admin->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'uji', 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.pengguna.password', $admin), [
                'password_baru' => 'passwordbaru1', 'password_baru_confirmation' => 'passwordbaru1',
            ])
            ->assertRedirect(route('admin-utama.pengguna'))
            ->assertSessionHas('sukses');

        $this->assertTrue(Hash::check('passwordbaru1', $admin->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
    }

    public function test_pengguna_belum_dipakai_bisa_dihapus(): void
    {
        $lain = User::factory()->adminUtama()->create();

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.pengguna.hapus', $lain))
            ->assertSessionHas('sukses');

        $this->assertDatabaseMissing('users', ['id' => $lain->id]);
    }

    public function test_daftar_menandai_akun_sendiri_dan_bisa_difilter_peran(): void
    {
        $cabang = $this->lokasi(Cabang::JENIS_CABANG, 'Cabang Jati');
        User::factory()->adminCabang($cabang->id)->create(['nama' => 'Admin Jati Satu']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.pengguna', ['peran' => User::ROLE_ADMIN_CABANG]))
            ->assertOk()
            ->assertSee('Admin Jati Satu')
            ->assertSee('Cabang Jati')
            ->assertDontSee($this->adminUtama->email);
    }
}
