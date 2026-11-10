<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Satuan;
use App\Models\User;
use App\Support\DaftarKontrol;
use App\View\MenuSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Menu Admin Utama yang bisa dibagikan ke Admin Pusat lewat Pengaturan > Hak Akses (bawaan mati). */
class AksesMenuUtamaTest extends TestCase
{
    use RefreshDatabase;

    private User $utama;
    private User $pusat;
    private User $cabang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utama = User::factory()->adminUtama()->create(['nama' => 'Bos Utama']);
        $gp = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $jbr = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->pusat = User::factory()->adminPusat($gp->id)->create(['nama' => 'Admin Gudang']);
        $this->cabang = User::factory()->adminCabang($jbr->id)->create(['nama' => 'Admin Jati']);
    }

    /** @param  list<string>  $menu  kunci menu tanpa awalan utama-, mis. ['satuan'] */
    private function izinkan(array $menu): void
    {
        $isian = [];
        foreach (DaftarKontrol::menuPeran(User::ROLE_ADMIN_PUSAT) as $kunci => $definisi) {
            if (isset($definisi['awalan'])) {
                $isian[$kunci]['lihat'] = in_array(substr($kunci, strlen('utama-')), $menu, true) ? '1' : '0';
            }
        }

        $this->actingAs($this->utama)
            ->put(route('admin-utama.kontrol-sistem.akses.simpan', 'pusat'), ['akses' => $isian])
            ->assertRedirect(route('admin-utama.pengaturan'));
    }

    private function semuaMenuUtama(): array
    {
        return collect(DaftarKontrol::menuPeran(User::ROLE_ADMIN_PUSAT))
            ->filter(fn ($d) => isset($d['awalan']))
            ->keys()->map(fn ($k) => substr($k, strlen('utama-')))->all();
    }

    public function test_bawaannya_admin_pusat_tidak_bisa_membuka_menu_admin_utama(): void
    {
        $this->actingAs($this->pusat)->get(route('admin-utama.satuan'))->assertForbidden();
        $this->actingAs($this->pusat)->get(route('admin-utama.persetujuan'))->assertForbidden();
        $this->actingAs($this->pusat)->post(route('admin-utama.satuan.simpan'), ['nama' => 'liter'])->assertForbidden();
        $this->assertArrayNotHasKey('Admin Utama', MenuSidebar::untuk($this->pusat));

        $this->actingAs($this->utama)->get(route('admin-utama.kontrol-sistem.akses', 'pusat'))
            ->assertOk()->assertSee('Menu Admin Utama')->assertSee('Stok Semua Lokasi');
        $this->actingAs($this->utama)->get(route('admin-utama.pengaturan'))->assertOk()->assertSee('0 dari 13');
    }

    public function test_menu_yang_diizinkan_terbuka_penuh_dan_yang_lain_tetap_tertutup(): void
    {
        $this->izinkan(['satuan']);

        $sidebar = collect(MenuSidebar::untuk($this->pusat)['Admin Utama'] ?? [])->pluck('route')->all();
        $this->assertSame(['admin-utama.satuan'], $sidebar);

        $this->actingAs($this->pusat)->get(route('admin-utama.satuan'))->assertOk();
        $this->actingAs($this->pusat)->post(route('admin-utama.satuan.simpan'), ['nama' => 'liter'])->assertSessionHasNoErrors();
        $this->assertTrue(Satuan::where('nama', 'liter')->exists());
        $this->actingAs($this->pusat)->get(route('admin-utama.excel.ekspor', 'satuan'))->assertOk();
        $this->actingAs($this->pusat)->get(route('admin-utama.riwayat', ['jenis' => 'satuan']))->assertOk();

        $this->actingAs($this->pusat)->get(route('admin-utama.bahan-baku'))->assertForbidden();
        $this->actingAs($this->pusat)->get(route('admin-utama.excel.ekspor', 'bahan-baku'))->assertForbidden();
        $this->actingAs($this->pusat)->get(route('admin-utama.riwayat', ['jenis' => 'bahan-baku']))->assertForbidden();
        $this->actingAs($this->cabang)->get(route('admin-utama.satuan'))->assertForbidden();

        $this->izinkan([]);
        $this->actingAs($this->pusat)->get(route('admin-utama.satuan'))->assertForbidden();
    }

    public function test_pengaturan_tetap_khusus_admin_utama_walau_semua_menu_diizinkan(): void
    {
        $this->izinkan($this->semuaMenuUtama());

        foreach (['admin-utama.pengaturan', 'admin-utama.dashboard', 'admin-utama.kiriman'] as $rute) {
            $this->actingAs($this->pusat)->get(route($rute))->assertForbidden();
        }
        $this->actingAs($this->pusat)->get(route('admin-utama.kontrol-sistem.akses', 'pusat'))->assertForbidden();
        $this->actingAs($this->pusat)->put(route('admin-utama.kontrol-sistem.akses.simpan', 'pusat'), ['akses' => []])->assertForbidden();
        $this->actingAs($this->pusat)->get(route('admin-utama.riwayat', ['jenis' => 'pengaturan']))->assertForbidden();
        $this->actingAs($this->pusat)->put(route('admin-utama.pengaturan.simpan'), [])->assertForbidden();
    }

    public function test_semua_halaman_menu_yang_diizinkan_bisa_dibuka_admin_pusat(): void
    {
        $this->izinkan($this->semuaMenuUtama());
        $pusat = $this->pusat;

        $terkunci = ['admin-utama.dashboard', 'admin-utama.kiriman', 'admin-utama.pengaturan', 'admin-utama.kontrol-sistem', 'admin-utama.riwayat'];
        $dibuka = 0;
        foreach (Route::getRoutes() as $r) {
            $nama = (string) $r->getName();
            if (! str_starts_with($nama, 'admin-utama.') || ! in_array('GET', $r->methods(), true) || str_contains($r->uri(), '{')
                || collect($terkunci)->contains(fn ($t) => $nama === $t || str_starts_with($nama, $t.'.'))) {
                continue;
            }

            $kode = $this->actingAs($pusat)->get(route($nama))->getStatusCode();
            $this->assertNotSame(403, $kode, $nama);
            $this->assertLessThan(500, $kode, $nama);
            $dibuka++;
        }

        $this->assertGreaterThan(20, $dibuka);
    }

    public function test_menu_pengguna_untuk_admin_pusat_tidak_bisa_menyentuh_admin_utama(): void
    {
        $this->izinkan(['pengguna']);
        $saya = $this->actingAs($this->pusat);

        $saya->get(route('admin-utama.pengguna'))->assertOk()
            ->assertSee('Bos Utama')
            ->assertDontSee(route('admin-utama.pengguna.ubah', $this->utama))
            ->assertSee(route('admin-utama.pengguna.ubah', $this->cabang));

        $saya->get(route('admin-utama.pengguna.ubah', $this->utama))->assertRedirect(route('admin-utama.pengguna'))->assertSessionHas('galat');
        $saya->put(route('admin-utama.pengguna.perbarui', $this->utama), ['nama' => 'X', 'email' => 'x@x.test', 'role' => 'admin_utama'])->assertForbidden();
        $saya->patch(route('admin-utama.pengguna.aktif', $this->utama), ['aktif' => '0'])->assertSessionHas('galat');
        $saya->delete(route('admin-utama.pengguna.hapus', $this->utama))->assertSessionHas('galat');
        $saya->patch(route('admin-utama.pengguna.password', $this->utama), ['password_baru' => 'rahasia999', 'password_baru_confirmation' => 'rahasia999'])
            ->assertSessionHas('galat');
        $this->utama->refresh();
        $this->assertTrue($this->utama->aktif);
        $this->assertSame('Bos Utama', $this->utama->nama);
        $this->assertFalse(Hash::check('rahasia999', $this->utama->password));

        $saya->get(route('admin-utama.pengguna.tambah'))->assertOk()->assertDontSee('value="admin_utama"', false);
        $saya->post(route('admin-utama.pengguna.simpan'), [
            'nama' => 'Calon Bos', 'email' => 'bos2@uji.test', 'role' => 'admin_utama', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertSessionHasErrors('role');
        $saya->put(route('admin-utama.pengguna.perbarui', $this->cabang), [
            'nama' => 'Admin Jati', 'email' => $this->cabang->email, 'role' => 'admin_utama',
        ])->assertSessionHasErrors('role');
        $saya->put(route('admin-utama.pengguna.perbarui', $this->pusat), [
            'nama' => 'Admin Gudang', 'email' => $this->pusat->email, 'role' => 'admin_cabang', 'cabang_id' => $this->cabang->cabang_id,
        ])->assertSessionHasErrors('role');
        $this->assertSame(0, User::where('email', 'bos2@uji.test')->count());

        $saya->post(route('admin-utama.pengguna.simpan'), [
            'nama' => 'Admin Baru', 'email' => 'baru@uji.test', 'role' => 'admin_cabang', 'cabang_id' => $this->cabang->cabang_id,
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'baru@uji.test')->exists());

        $saya->patch(route('admin-utama.pengguna.password', $this->cabang), ['password_baru' => 'rahasia999', 'password_baru_confirmation' => 'rahasia999'])
            ->assertSessionHas('sukses');
        $this->assertTrue(Hash::check('rahasia999', $this->cabang->fresh()->password));
    }

    public function test_persetujuan_yang_diizinkan_muncul_di_menu_admin_pusat(): void
    {
        $this->izinkan(['persetujuan']);

        $item = collect(MenuSidebar::untuk($this->pusat)['Admin Utama'])->firstWhere('route', 'admin-utama.persetujuan');
        $this->assertSame('persetujuan_menunggu', $item['jumlah']);
        $this->actingAs($this->pusat)->get(route('admin-utama.persetujuan'))->assertOk();
    }
}
