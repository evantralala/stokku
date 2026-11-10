<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\LogPerubahan;
use App\Models\User;
use App\Services\LogPerubahanService;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogPerubahanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create(['nama' => 'Utama Satu']);
    }

    public function test_perubahan_pengaturan_tercatat_dengan_nilai_lama_dan_baru(): void
    {
        $this->seed(PengaturanSeeder::class);

        $nilai = ['batas_jam_persetujuan' => '24', 'jam_permintaan_otomatis' => '06:00', 'jam_ganti_hari_penjualan' => '06:00', 'toleransi_default' => '5', 'ambang_stok_menipis' => '20', 'persen_cadangan_minta' => '20'];

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.pengaturan.simpan'), ['nilai' => $nilai])
            ->assertSessionHas('sukses', 'Tidak ada pengaturan yang berubah.');
        $this->assertSame(0, LogPerubahan::count());

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.pengaturan.simpan'), ['nilai' => ['batas_jam_persetujuan' => '12'] + $nilai])
            ->assertSessionHas('sukses', '1 pengaturan berhasil diperbarui.');

        $log = LogPerubahan::sole();
        $this->assertSame(LogPerubahan::KATEGORI_PENGATURAN, $log->kategori);
        $this->assertSame('Pengaturan diubah: Batas Waktu Persetujuan', $log->ringkasan);
        $this->assertSame([['label' => 'Batas Waktu Persetujuan', 'lama' => '24 jam', 'baru' => '12 jam']], $log->rincian);
    }

    public function test_tambah_ubah_hapus_dan_password_pengguna_tercatat_tanpa_password(): void
    {
        $cabang = Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.pengguna.simpan'), [
            'nama' => 'Fitri', 'email' => 'fitri@contoh.com', 'role' => User::ROLE_ADMIN_CABANG, 'cabang_id' => $cabang->id,
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertSessionHasNoErrors();

        $fitri = User::firstWhere('email', 'fitri@contoh.com');

        $this->actingAs($this->adminUtama)->put(route('admin-utama.pengguna.perbarui', $fitri), [
            'nama' => 'Fitri Nuraini', 'email' => 'fitri@contoh.com', 'role' => User::ROLE_ADMIN_UTAMA,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->adminUtama)->patch(route('admin-utama.pengguna.password', $fitri), [
            'password_baru' => 'passwordbaru1', 'password_baru_confirmation' => 'passwordbaru1',
        ])->assertSessionHas('sukses');

        $this->actingAs($this->adminUtama)->delete(route('admin-utama.pengguna.hapus', $fitri))->assertSessionHas('sukses');

        $log = LogPerubahan::orderBy('id')->get();
        $this->assertSame(['pengguna.tambah', 'pengguna.ubah', 'pengguna.password', 'pengguna.hapus'], $log->pluck('aksi')->all());
        $this->assertSame([
            ['label' => 'Nama', 'lama' => 'Fitri', 'baru' => 'Fitri Nuraini'],
            ['label' => 'Peran', 'lama' => 'Admin Cabang', 'baru' => 'Admin Utama'],
            ['label' => 'Lokasi', 'lama' => 'Cabang Melati', 'baru' => 'Semua Lokasi'],
        ], $log[1]->rincian);
        $this->assertStringNotContainsString('rahasia123', $log->toJson());
        $this->assertStringNotContainsString('passwordbaru1', $log->toJson());
    }

    public function test_ubah_aktif_dari_halaman_pengguna_tercatat(): void
    {
        $lain = User::factory()->adminUtama()->create(['nama' => 'Utama Dua']);

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.pengguna.aktif', $lain), ['aktif' => 0])
            ->assertSessionHas('sukses');

        $log = LogPerubahan::sole();
        $this->assertSame(LogPerubahan::KATEGORI_AKUN, $log->kategori);
        $this->assertSame('Akun dimatikan: Utama Dua', $log->ringkasan);
    }

    public function test_log_tidak_bisa_diubah_atau_dihapus_dan_pencatatnya_tidak_bisa_dihapus(): void
    {
        $log = app(LogPerubahanService::class)->catat($this->adminUtama, LogPerubahan::KATEGORI_SISTEM, 'perbaikan.nyala', 'Mode perbaikan dinyalakan');

        $this->assertFalse($log->update(['ringkasan' => 'Diubah']));
        $this->assertFalse($log->delete());
        $this->assertSame('Mode perbaikan dinyalakan', $log->fresh()->ringkasan);
        $this->assertTrue($this->adminUtama->sudahDipakai());
    }

    public function test_riwayat_tetap_tampil_untuk_rincian_log_bentuk_apa_pun(): void
    {
        $log = app(LogPerubahanService::class);
        $log->catat($this->adminUtama, LogPerubahan::KATEGORI_PENGATURAN, 'a', 'Bentuk lama porsi', ['porsi' => ['Gyudon' => '8 → 3']]);
        $log->catat($this->adminUtama, LogPerubahan::KATEGORI_PENGATURAN, 'b', 'Bentuk kunci nilai', ['dari' => 'GP', 'ke' => 'JTI', 'kiriman' => null, 'bahan_minus' => ['Nori']]);
        $log->catat($this->adminUtama, LogPerubahan::KATEGORI_PENGATURAN, 'c', 'Bentuk standar', [['label' => 'Beras', 'lama' => '8 kg', 'baru' => '0 kg']]);

        $this->actingAs($this->adminUtama)->get(route('admin-utama.riwayat', ['jenis' => 'pengaturan']))
            ->assertOk()
            ->assertSeeInOrder(['Beras', '8 kg', '0 kg'])
            ->assertSee('Gyudon')
            ->assertSee('8 → 3')
            ->assertSee('Dari')
            ->assertSee('Bahan minus');
    }
}
