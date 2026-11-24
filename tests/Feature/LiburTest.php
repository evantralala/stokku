<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\PenjualanHarian;
use App\Models\PenjualanLibur;
use App\Models\User;
use App\Services\KontrolSistemService;
use App\Services\PenjualanService;
use App\Services\PeringatanDiniService;
use App\Support\DaftarKontrol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hari libur cabang: ditandai Admin Cabang, Admin Pusat, atau Admin Utama; tidak diingatkan "belum dicatat". */
class LiburTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private Cabang $lain;
    private User $adminCabang;
    private User $adminPusat;
    private User $adminUtama;
    private Menu $shoyu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(21, 0));
        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->lain = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        foreach ([$this->cabang, $this->lain] as $c) {
            $c->forceFill(['created_at' => now()->subDays(10)])->save();
        }
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();
        $this->adminPusat = User::factory()->adminPusat($pusat->id)->create();
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1])->id]);
    }

    private function hari(int $mundur): string
    {
        return now()->subDays($mundur)->toDateString();
    }

    /** Semua aksi peran ini boleh, kecuali yang disebut di $mati (mis. ['libur.catat']). */
    private function atur(string $role, array $mati): void
    {
        $isian = [];
        foreach (DaftarKontrol::menuPeran($role) as $menu => $definisi) {
            foreach (DaftarKontrol::bisaDiatur($definisi) as $aksi) {
                $isian[$menu][$aksi] = ! in_array($menu.'.'.$aksi, $mati, true) && ! isset($definisi['awalan']);
            }
        }
        app(KontrolSistemService::class)->aturAkses($role, $isian, $this->adminUtama);
    }

    public function test_cabang_menandai_libur_dari_hari_yang_belum_dicatat(): void
    {
        app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, $this->hari(2), [$this->shoyu->id => 3]);

        $this->actingAs($this->adminCabang)->get(route('cabang.dashboard'))->assertOk()
            ->assertSee(route('cabang.libur.catat', ['tanggal' => $this->hari(1)]), false);

        $popup = $this->get(route('cabang.libur.catat'))->assertOk()->assertSee('data-popup-isi', false);
        $this->assertSame([$this->hari(0), $this->hari(1), $this->hari(3)], array_slice(array_keys($popup->viewData('pilihanTanggal')), 0, 3), 'hari ini lalu hari terlewat; tanggal yang sudah dicatat tidak ada');
        $popup->assertSeeInOrder(['Hari Ini', 'Kemarin']);

        $this->from(route('cabang.dashboard'))->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(1)])
            ->assertRedirect(route('cabang.dashboard'))->assertSessionHas('sukses');

        $libur = PenjualanLibur::sole();
        $this->assertSame([$this->cabang->id, $this->hari(1), $this->adminCabang->id], [$libur->cabang_id, $libur->tanggal->toDateString(), $libur->dicatat_oleh]);
        $this->assertNotContains($this->hari(1), array_map(fn ($t) => $t->toDateString(), app(PenjualanService::class)->tanggalTerlewat($this->cabang)));
        $this->assertDatabaseHas('log_perubahan', ['aksi' => 'libur.tandai']);

        $this->get(route('cabang.penjualan'))->assertOk()
            ->assertSee('class="baris-libur"', false)
            ->assertSeeInOrder([now()->subDay()->translatedFormat('l, j M'), 'Libur', 'Batalkan', now()->subDays(2)->translatedFormat('l, j M')]);

        $this->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(2)])->assertSessionHas('galat', 'Penjualan tanggal ini sudah dicatat, jadi tidak bisa ditandai libur.');
        $this->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(8)])->assertSessionHasErrors('tanggal');
        $this->post(route('cabang.libur.simpan'), ['tanggal' => now()->addDay()->toDateString()])->assertSessionHasErrors('tanggal');
        $this->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(1)])->assertSessionHas('sukses');
        $this->assertSame(1, PenjualanLibur::count(), 'menandai dua kali tidak dobel');
    }

    public function test_libur_hari_ini_dan_batal(): void
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(0)])->assertSessionHas('sukses');

        $this->get(route('cabang.penjualan'))->assertOk()
            ->assertSee('tidak ada penjualan')
            ->assertDontSee('belum dicatat. Catat setelah tutup.');
        $this->get(route('cabang.dashboard'))->assertOk()->assertSee('tidak ada penjualan');
        $this->actingAs($this->adminUtama)->get(route('admin-utama.dashboard'))->assertOk()->assertSee('<span class="badge b-pasif">Libur</span>', false);

        $libur = PenjualanLibur::sole();
        $milikLain = PenjualanLibur::create(['cabang_id' => $this->lain->id, 'tanggal' => $this->hari(0)]);
        $this->actingAs($this->adminCabang)->delete(route('cabang.libur.hapus', $milikLain))->assertNotFound();
        $this->from(route('cabang.penjualan'))->delete(route('cabang.libur.hapus', $libur))->assertRedirect(route('cabang.penjualan'))->assertSessionHas('sukses');
        $this->assertModelMissing($libur);
        $this->assertDatabaseHas('log_perubahan', ['aksi' => 'libur.batal']);
    }

    public function test_ternyata_buka_mencatat_penjualan_menghapus_libur(): void
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(1)]);
        $this->assertSame(1, PenjualanLibur::count());

        app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, $this->hari(1), [$this->shoyu->id => 2]);
        $this->assertSame(0, PenjualanLibur::count());

        app(\App\Services\LiburService::class)->tandai($this->adminUtama, $this->lain, $this->hari(3));
        app(PenjualanService::class)->terapkanImpor($this->lain, $this->hari(3), [$this->shoyu->id => 4], $this->adminUtama);
        $this->assertSame(0, PenjualanLibur::count(), 'impor Admin Utama juga menghapus libur');
    }

    public function test_peringatan_admin_utama_tidak_menghitung_hari_libur(): void
    {
        $judul = fn () => app(PeringatanDiniService::class)->daftar()->where('lokasi_id', $this->cabang->id)->pluck('judul')->all();
        $this->assertContains('Penjualan 3 hari terakhir belum dicatat', $judul());

        foreach ([1, 2, 3] as $h) {
            app(\App\Services\LiburService::class)->tandai($this->adminUtama, $this->cabang, $this->hari($h));
        }

        $this->assertSame([], array_values(array_filter($judul(), fn ($j) => str_starts_with($j, 'Penjualan'))));
    }

    public function test_admin_utama_dan_admin_pusat_memilih_cabang(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.libur.catat', ['cabang' => $this->lain->id]))->assertOk()
            ->assertSee('Pilih cabang')->assertSee('min="'.$this->hari(31).'"', false);
        $this->from(route('admin-utama.penjualan'))->post(route('admin-utama.libur.simpan'), ['cabang_id' => $this->lain->id, 'tanggal' => $this->hari(20)])
            ->assertRedirect(route('admin-utama.penjualan'))->assertSessionHas('sukses');
        $this->post(route('admin-utama.libur.simpan'), ['tanggal' => $this->hari(1)])->assertSessionHasErrors('cabang_id');

        $this->get(route('admin-utama.penjualan'))->assertOk()
            ->assertSee(route('admin-utama.libur.catat'), false)
            ->assertSee('Cabang Kenanga')->assertSee('class="baris-libur"', false);
        $this->get(route('admin-utama.laporan.penjualan', ['dari' => $this->hari(30), 'sampai' => $this->hari(0)]))->assertOk()->assertSee('1 hari libur');

        $this->actingAs($this->adminPusat)->get(route('pusat.libur'))->assertOk()->assertSee('Cabang Kenanga')->assertSee(route('pusat.libur.catat'), false);
        $this->actingAs($this->adminPusat)->get(route('pusat.libur.catat'))->assertOk()->assertSee('min="'.$this->hari(7).'"', false);
        $this->post(route('pusat.libur.simpan'), ['cabang_id' => $this->cabang->id, 'tanggal' => $this->hari(20)])->assertSessionHasErrors('tanggal');
        $this->post(route('pusat.libur.simpan'), ['cabang_id' => $this->cabang->id, 'tanggal' => $this->hari(2)])->assertSessionHas('sukses');
        $this->post(route('pusat.libur.simpan'), ['cabang_id' => Cabang::pusat()->value('id'), 'tanggal' => $this->hari(2)])->assertSessionHasErrors('cabang_id');
        $this->get(route('admin-utama.libur.catat'))->assertForbidden();
        $this->assertSame(2, PenjualanLibur::count());
    }

    public function test_admin_utama_mematikan_libur_per_peran(): void
    {
        $this->atur(User::ROLE_ADMIN_CABANG, ['libur.catat']);
        $this->actingAs($this->adminCabang)->get(route('cabang.penjualan'))->assertOk()->assertDontSee(route('cabang.libur.catat'), false);
        $this->get(route('cabang.libur.catat'))->assertRedirect(route('cabang.dashboard'))->assertSessionHas('galat');
        $this->post(route('cabang.libur.simpan'), ['tanggal' => $this->hari(1)])->assertSessionHas('galat');
        $this->get(route('cabang.dashboard'))->assertOk()->assertDontSee('Tandai libur');

        $this->atur(User::ROLE_ADMIN_PUSAT, ['libur.catat']);
        $this->actingAs($this->adminPusat)->get(route('pusat.libur'))->assertOk()->assertDontSee(route('pusat.libur.catat'), false);
        $this->post(route('pusat.libur.simpan'), ['cabang_id' => $this->cabang->id, 'tanggal' => $this->hari(1)])->assertSessionHas('galat');

        $this->atur(User::ROLE_ADMIN_PUSAT, ['libur.lihat', 'libur.catat']);
        $this->get(route('pusat.libur'))->assertRedirect(route('pusat.dashboard'));
        $this->get(route('pusat.dashboard'))->assertOk()->assertDontSee(route('pusat.libur'), false);

        $this->assertSame(0, PenjualanLibur::count());
        $this->actingAs($this->adminUtama)->post(route('admin-utama.libur.simpan'), ['cabang_id' => $this->cabang->id, 'tanggal' => $this->hari(1)])->assertSessionHas('sukses');
    }
}
