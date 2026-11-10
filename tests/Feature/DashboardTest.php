<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(20, 0));

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $gram = Satuan::create(['nama' => 'gram']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
        BahanBakuMinimum::create(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 500]);
        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => -20, 'diperbarui_pada' => now()]);
    }

    public function test_dashboard_admin_utama_menampilkan_peringatan_dan_ringkasan_lokasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSee('Menunggu Keputusan')
            ->assertSee('Peringatan')
            ->assertSee('1 bahan stoknya minus')
            ->assertSeeInOrder(['Ringkasan per Lokasi', 'Gudang Pusat', 'Cabang Jati', 'Belum Dicatat']);
    }

    public function test_dashboard_pusat_menampilkan_bahan_gudang_yang_perlu_diisi(): void
    {
        $admin = User::factory()->adminPusat($this->pusat->id)->create();

        $this->actingAs($admin)
            ->get(route('pusat.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['<nav class="tombol-cepat-grid" aria-label="Catat cepat">', 'Kirim Barang', 'Barang Masuk', 'Barang Rusak', 'Opname'], false)
            ->assertSee('Perlu Diisi')
            ->assertSee('Nori');
    }

    public function test_dashboard_cabang_mengingatkan_penjualan_hari_ini(): void
    {
        $admin = User::factory()->adminCabang($this->cabang->id)->create();
        $menu = Menu::create(['nama' => 'Ocha', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Minuman'])->id]);

        $this->actingAs($admin)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSee('Belum Dicatat')
            ->assertSee('Catat Sekarang')
            ->assertSeeInOrder(['<nav class="tombol-cepat-grid" aria-label="Catat cepat">', 'Terima Barang', 'Minta Barang', 'Barang Rusak', 'Opname'], false);

        $this->actingAs($admin)->post(route('cabang.penjualan.simpan'), [
            'tanggal' => now()->toDateString(), 'porsi' => [$menu->id => '10'],
        ]);

        $this->actingAs($admin)
            ->get(route('cabang.dashboard'))
            ->assertSee('Sudah dicatat: 10 porsi');
    }

    public function test_akun_admin_tanpa_lokasi_yang_benar_melihat_pesan(): void
    {
        $admin = User::factory()->adminPusat($this->cabang->id)->create();

        $this->actingAs($admin)
            ->get(route('pusat.dashboard'))
            ->assertOk()
            ->assertSee('belum terhubung ke Gudang Pusat yang aktif');
    }
}
