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
use App\Services\KontrolSistemService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Celah yang ditutup supaya admin lokasi tidak bisa mengakali stok. */
class AntiCurangTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $cabang;
    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private BahanBaku $mie;
    private Menu $shoyu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $this->mie = BahanBaku::create([
            'nama' => 'Mie ramen', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen'])->id]);
        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->mie->id, 'jumlah' => 100, 'berlaku_sejak' => now()->subDays(10)->toDateString()]);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 9000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 5000, 'diperbarui_pada' => now()],
        ]);
    }

    private function saldo(Cabang $lokasi): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $this->mie->id)->value('saldo');
    }

    public function test_admin_pusat_bawaannya_tidak_bisa_mengubah_stok_langsung(): void
    {
        $this->actingAs($this->adminPusat)->get(route('pusat.stok'))
            ->assertOk()
            ->assertDontSee(route('pusat.stok.tambah'), false)
            ->assertDontSee('Perbarui Lewat Excel')
            ->assertDontSee('aria-label="Ubah stok Mie ramen"', false);

        $this->post(route('pusat.stok.tambah.simpan'), ['bahan_baku_id' => $this->mie->id, 'jumlah' => '5'])->assertSessionHas('galat');
        $this->post('/pusat/stok/'.$this->mie->id.'/ubah', ['stok_lama' => '9000', 'stok_baru' => '1'])->assertNotFound();
        $this->assertSame(9000.0, $this->saldo($this->pusat));

        $this->assertFalse(app(KontrolSistemService::class)->tabelAkses(User::ROLE_ADMIN_PUSAT)['ubah-stok']['aksi']['catat']['boleh']);
        $this->actingAs($this->adminUtama)->get(route('admin-utama.kontrol-sistem.akses', 'pusat'))->assertOk()->assertSee('Tambah Stok', false);

        $this->izinkanUbahStokPusat();
        $this->actingAs($this->adminPusat)->get(route('pusat.stok.tambah'))->assertOk();
    }

    public function test_cabang_tidak_bisa_mengubah_penjualan_hasil_impor_admin_utama(): void
    {
        $tanggal = now()->toDateString();
        $impor = app(PenjualanService::class)->terapkanImpor($this->cabang, $tanggal, [$this->shoyu->id => 10], $this->adminUtama);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.penjualan.lihat', $impor))
            ->assertOk()
            ->assertDontSee(route('cabang.penjualan.ubah', $impor), false);

        $this->get(route('cabang.penjualan.ubah', $impor))
            ->assertRedirect(route('cabang.penjualan.lihat', $impor))
            ->assertSessionHas('galat', 'Penjualan ini diimpor Admin Utama, jadi tidak bisa diubah dari cabang.');

        $this->put(route('cabang.penjualan.perbarui', $impor), ['tanggal' => $tanggal, 'porsi' => [$this->shoyu->id => '1']])->assertSessionHas('galat');
        $this->assertSame(4000.0, $this->saldo($this->cabang));

        $this->post(route('cabang.penjualan.simpan'), ['tanggal' => $tanggal, 'porsi' => [$this->shoyu->id => '1']])->assertSessionHas('galat');
        $this->assertSame(1, PenjualanHarian::count());
    }

    public function test_perubahan_penjualan_oleh_cabang_tercatat_di_log(): void
    {
        $this->actingAs($this->adminCabang)
            ->post(route('cabang.penjualan.simpan'), ['tanggal' => now()->toDateString(), 'porsi' => [$this->shoyu->id => '10']]);
        $penjualan = PenjualanHarian::sole();

        $this->put(route('cabang.penjualan.perbarui', $penjualan), ['tanggal' => now()->toDateString(), 'porsi' => [$this->shoyu->id => '4']])->assertSessionHas('sukses');

        $log = LogPerubahan::where('aksi', 'penjualan.ubah')->sole();
        $this->assertSame($this->adminCabang->id, $log->user_id);
        $this->assertStringContainsString('Shoyu Ramen 10 → 4 porsi', $log->ringkasan);
        $this->assertSame(4600.0, $this->saldo($this->cabang));
    }

    public function test_barang_rusak_tanpa_alasan_ditolak(): void
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.barang-keluar.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->mie->id, 'qty' => '1', 'alasan' => '   ']],
        ])->assertSessionHasErrors(['baris.0.alasan' => 'Tulis alasannya, mis. kemasan bocor.']);

        $this->assertSame(0, \App\Models\BarangKeluar::rusak()->count());
        $this->assertSame(5000.0, $this->saldo($this->cabang));
    }
}
