<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\PenjualanDetail;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuResepTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private KategoriMenu $ramen;
    private BahanBaku $mie;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->ramen = KategoriMenu::create(['nama' => 'Ramen']);

        $kategori = Kategori::create(['nama' => 'Kering']);
        $gram = Satuan::create(['nama' => 'gram']);
        $bahan = fn (string $kode, string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);

        $this->mie = $bahan('BB-0001', 'Mie ramen');
        $this->nori = $bahan('BB-0002', 'Nori');
    }

    private function buatMenu(): Menu
    {
        return Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $this->ramen->id]);
    }

    private function simpanResep(Menu $menu, string $tanggal, array $baris)
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.menu.resep.simpan', $menu), [
            'berlaku_sejak' => $tanggal,
            'baris'         => $baris,
        ]);
    }

    public function test_menu_baru_diarahkan_ke_resep(): void
    {
        $respons = $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.menu.simpan'), ['nama' => 'Miso Ramen', 'kategori_menu_id' => $this->ramen->id]);

        $menu = Menu::firstWhere('nama', 'Miso Ramen');
        $respons->assertRedirect(route('admin-utama.menu.resep.ubah', $menu));
    }

    public function test_simpan_resep_hari_ini_dengan_takaran_koma(): void
    {
        $menu = $this->buatMenu();

        $this->simpanResep($menu, now()->toDateString(), [
            ['bahan_baku_id' => $this->mie->id, 'jumlah' => '150', 'catatan' => ''],
            ['bahan_baku_id' => $this->nori->id, 'jumlah' => '2,5', 'catatan' => 'dipotong dua'],
            ['bahan_baku_id' => '', 'jumlah' => '', 'catatan' => ''],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin-utama.menu.resep', $menu));

        $resep = $menu->resepBerlaku();
        $this->assertCount(2, $resep);
        $this->assertSame('2.500', $resep->firstWhere('bahan_baku_id', $this->nori->id)->jumlah);
        $this->assertNull($resep->firstWhere('bahan_baku_id', $this->nori->id)->catatan);
        $this->get(route('admin-utama.menu.resep.ubah', $menu))->assertOk()->assertDontSee('[catatan]', false);
    }

    public function test_versi_baru_tidak_mengubah_resep_tanggal_sebelumnya(): void
    {
        $menu = $this->buatMenu();
        $hariIni = now()->toDateString();
        $besok = now()->addDay()->toDateString();

        $this->simpanResep($menu, now()->toDateString(), [['bahan_baku_id' => $this->mie->id, 'jumlah' => '150']]);
        $this->simpanResep($menu, $besok, [['bahan_baku_id' => $this->mie->id, 'jumlah' => '180']]);

        $this->assertSame('150.000', $menu->resepBerlaku($hariIni)->first()->jumlah);
        $this->assertSame('180.000', $menu->resepBerlaku($besok)->first()->jumlah);

        // Salinan untuk penjualan hari ini tetap memakai takaran hari ini.
        $salinan = PenjualanDetail::salinResep($menu, $hariIni);
        $this->assertSame(150.0, $salinan[0]['jumlah']);
        $this->assertSame('gram', $salinan[0]['satuan']);
    }

    public function test_tanggal_yang_sudah_lewat_ditolak(): void
    {
        $menu = $this->buatMenu();

        $this->simpanResep($menu, now()->subDay()->toDateString(), [['bahan_baku_id' => $this->mie->id, 'jumlah' => '150']])
            ->assertSessionHasErrors('berlaku_sejak');

        $this->assertSame(0, Resep::count());
    }

    public function test_bahan_ganda_dan_takaran_nol_ditolak_per_baris(): void
    {
        $menu = $this->buatMenu();

        $this->simpanResep($menu, now()->toDateString(), [
            ['bahan_baku_id' => $this->mie->id, 'jumlah' => '150'],
            ['bahan_baku_id' => $this->mie->id, 'jumlah' => '0'],
        ])->assertSessionHasErrors(['baris.1.bahan_baku_id', 'baris.1.jumlah']);
    }

    public function test_simpan_ulang_tanggal_sama_mengganti_isi_versi(): void
    {
        $menu = $this->buatMenu();
        $hariIni = now()->toDateString();

        $this->simpanResep($menu, now()->toDateString(), [['bahan_baku_id' => $this->mie->id, 'jumlah' => '150']]);
        $this->simpanResep($menu, now()->toDateString(), [['bahan_baku_id' => $this->nori->id, 'jumlah' => '3']]);

        $this->assertSame(1, Resep::count());
        $this->assertSame($this->nori->id, $menu->resepBerlaku()->first()->bahan_baku_id);
    }

    public function test_hanya_jadwal_masa_depan_yang_bisa_dihapus(): void
    {
        $menu = $this->buatMenu();
        $hariIni = now()->toDateString();
        $besok = now()->addDay()->toDateString();

        $this->simpanResep($menu, now()->toDateString(), [['bahan_baku_id' => $this->mie->id, 'jumlah' => '150']]);
        $this->simpanResep($menu, $besok, [['bahan_baku_id' => $this->mie->id, 'jumlah' => '180']]);

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.menu.resep.hapus', [$menu, $hariIni]))
            ->assertSessionHas('galat');

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.menu.resep.hapus', [$menu, $besok]))
            ->assertSessionHas('sukses');

        $this->assertSame([$hariIni], $menu->versiResep()->all());
    }

    public function test_halaman_resep_menampilkan_berlaku_dan_terjadwal(): void
    {
        $menu = $this->buatMenu();
        $this->simpanResep($menu, now()->toDateString(), [['bahan_baku_id' => $this->mie->id, 'jumlah' => '150']]);
        $this->simpanResep($menu, now()->addWeek()->toDateString(), [['bahan_baku_id' => $this->nori->id, 'jumlah' => '2']]);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.menu.resep', $menu))
            ->assertOk()
            ->assertSeeInOrder(['Resep yang Berlaku', 'Mie ramen', 'Perubahan Terjadwal', 'Nori']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.menu.resep.ubah', $menu))
            ->assertOk()
            ->assertSee('value="Mie ramen"', false)
            ->assertSee('id="data-bahan"', false);
    }

    public function test_menu_belum_terjual_terhapus_bersama_resepnya(): void
    {
        $menu = $this->buatMenu();
        $this->simpanResep($menu, now()->toDateString(), [['bahan_baku_id' => $this->mie->id, 'jumlah' => '150']]);

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.menu.hapus', $menu))
            ->assertSessionHas('sukses');

        $this->assertSame(0, Menu::count());
        $this->assertSame(0, Resep::count());
    }

    public function test_kategori_menu_yang_dipakai_tidak_bisa_dihapus(): void
    {
        $this->buatMenu();

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.kategori-menu.hapus', $this->ramen))
            ->assertSessionHas('galat');

        $this->assertDatabaseHas('kategori_menu', ['id' => $this->ramen->id]);
    }
}
