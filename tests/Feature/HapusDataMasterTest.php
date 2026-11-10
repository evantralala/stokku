<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\PenjualanHarian;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Hapus data master: belum dipakai = permanen; sudah dipakai = arsip (riwayat utuh); tambah lagi = pulihkan. */
class HapusDataMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminCabang;
    private Cabang $cabang;
    private Kategori $kategori;
    private Satuan $gram;
    private Satuan $kg;
    private BahanBaku $mie;
    private Menu $shoyu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(20, 0));
        Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $this->gram = Satuan::create(['nama' => 'gram']);
        $this->kg = Satuan::create(['nama' => 'kg']);
        $this->kategori = Kategori::create(['nama' => 'Kering', 'urutan' => 1]);
        $this->mie = BahanBaku::create(['nama' => 'Mie Ramen', 'kategori_id' => $this->kategori->id, 'satuan_dasar_id' => $this->gram->id,
            'satuan_beli_id' => $this->kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5]);
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1])->id]);
        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->mie->id, 'jumlah' => 100, 'berlaku_sejak' => now()->subDays(5)->toDateString()]);
        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);
    }

    public function test_daftar_punya_ikon_hapus_di_sebelah_ubah(): void
    {
        foreach (['satuan' => $this->gram, 'kategori' => $this->kategori, 'bahan-baku' => $this->mie, 'menu' => $this->shoyu, 'lokasi' => $this->cabang, 'pengguna' => $this->adminCabang] as $rute => $item) {
            $this->actingAs($this->adminUtama)->get(route('admin-utama.'.$rute))->assertOk()
                ->assertSeeInOrder([route('admin-utama.'.$rute.'.ubah', $item), 'action="'.route('admin-utama.'.$rute.'.hapus', $item).'"'], false)
                ->assertSee('tombol-ikon-hapus', false);
        }

        $this->get(route('admin-utama.pengguna'))->assertDontSee('action="'.route('admin-utama.pengguna.hapus', $this->adminUtama).'"', false);
    }

    public function test_belum_pernah_dipakai_dihapus_permanen(): void
    {
        $sachet = Satuan::create(['nama' => 'sachet']);

        $this->actingAs($this->adminUtama)->delete(route('admin-utama.satuan.hapus', $sachet))
            ->assertRedirect(route('admin-utama.satuan'))->assertSessionHas('sukses', 'Satuan sachet dihapus.');

        $this->assertDatabaseMissing('satuan', ['nama' => 'sachet']);
    }

    public function test_menu_yang_pernah_terjual_diarsipkan_riwayat_tetap_dan_bisa_ditambah_lagi(): void
    {
        $penjualan = app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->toDateString(), [$this->shoyu->id => 3]);

        $this->actingAs($this->adminUtama)->delete(route('admin-utama.menu.hapus', $this->shoyu))
            ->assertRedirect(route('admin-utama.menu'))->assertSessionHas('sukses');

        $this->shoyu->refresh();
        $this->assertNotNull($this->shoyu->dihapus_pada);
        $this->assertFalse($this->shoyu->aktif);
        $this->assertTrue(LogPerubahan::where('ringkasan', 'like', 'Dihapus (riwayat disimpan)%Shoyu Ramen')->exists());

        $this->get(route('admin-utama.menu', ['status' => 'semua']))->assertDontSee(route('admin-utama.menu.ubah', $this->shoyu), false);
        $this->actingAs($this->adminCabang)->get(route('cabang.penjualan.lihat', $penjualan))->assertOk()->assertSee('Shoyu Ramen');
        $this->get(route('cabang.penjualan.catat'))->assertDontSee('Shoyu Ramen');

        $this->actingAs($this->adminUtama)->post(route('admin-utama.menu.simpan'), [
            'nama' => 'shoyu ramen', 'kategori_menu_id' => $this->shoyu->kategori_menu_id,
        ])->assertSessionHas('sukses', 'Menu shoyu ramen ditambahkan kembali. Riwayat lamanya tersambung lagi. Periksa resepnya.');

        $this->assertSame(1, Menu::count());
        $this->shoyu->refresh();
        $this->assertNull($this->shoyu->dihapus_pada);
        $this->assertTrue($this->shoyu->aktif);
        $this->assertSame($this->shoyu->id, PenjualanHarian::sole()->detail()->value('menu_id'));
    }

    public function test_bahan_masih_ada_stok_atau_resep_harus_dibereskan_dulu(): void
    {
        $this->actingAs($this->adminUtama)->delete(route('admin-utama.bahan-baku.hapus', $this->mie))
            ->assertSessionHas('galat', 'Stok Mie Ramen masih ada di Cabang Jati. Kosongkan stoknya dulu (Stok → ikon sampah), lalu hapus lagi.');

        DB::table('stok_saldo')->update(['saldo' => 0]);
        DB::table('stok_mutasi')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'jumlah' => 1, 'jenis' => 'penyesuaian',
            'status' => 'sah', 'dokumen_tipe' => 'penyesuaian_stok', 'dokumen_id' => 1, 'dibuat_pada' => now()]);

        $this->delete(route('admin-utama.bahan-baku.hapus', $this->mie))
            ->assertSessionHas('galat', 'Mie Ramen masih dipakai di resep Shoyu Ramen. Ubah resepnya dulu.');

        $this->shoyu->update(['aktif' => false]);
        $this->delete(route('admin-utama.bahan-baku.hapus', $this->mie))->assertSessionHas('sukses');
        $this->assertNotNull($this->mie->fresh()->dihapus_pada);

        $this->delete(route('admin-utama.kategori.hapus', $this->kategori))->assertSessionHas('sukses');
        $this->assertNotNull($this->kategori->fresh()->dihapus_pada, 'kategori tanpa bahan aktif diarsipkan (masih dirujuk bahan arsip)');
    }

    public function test_impor_excel_memulihkan_arsip(): void
    {
        $this->shoyu->forceFill(['aktif' => false, 'dihapus_pada' => now()])->save();

        $file = UploadedFile::fake()->createWithContent('menu.csv', "nama,kategori_menu\nShoyu Ramen,Ramen\n");
        $pratinjau = $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.pratinjau', 'menu'), ['file' => $file])->assertOk()
            ->assertSee('Pernah dihapus: ditambahkan kembali');
        $this->post(route('admin-utama.excel.simpan', 'menu'), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertSessionHas('sukses', '1 menu disimpan, 1 ditambahkan kembali.');

        $this->assertSame(1, Menu::count());
        $this->assertNull($this->shoyu->fresh()->dihapus_pada);
    }

    public function test_pengguna_dan_lokasi(): void
    {
        $this->actingAs($this->adminUtama)->delete(route('admin-utama.pengguna.hapus', $this->adminUtama))->assertSessionHas('galat');
        $this->assertNull($this->adminUtama->fresh()->dihapus_pada);

        $this->delete(route('admin-utama.lokasi.hapus', $this->cabang))
            ->assertSessionHas('galat', 'Masih ada stok 1 bahan di Cabang Jati. Kosongkan stoknya dulu (Stok → Kosongkan), lalu hapus lagi.');
    }
}
