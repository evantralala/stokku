<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Kiriman;
use App\Models\Menu;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use App\Services\KontrolSistemService;
use App\Services\PermintaanService;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Revisi 17 Okt 2026: kirim dari Persetujuan, konfirmasi terima, Web Order dihapus, pembaruan otomatis. */
class RevisiAlurTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $ayam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JB', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->ayam = BahanBaku::create([
            'nama' => 'Ayam fillet', 'kategori_id' => Kategori::create(['nama' => 'Protein'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->ayam->id, 'saldo' => 20000, 'diperbarui_pada' => now()]);
    }

    private function saldo(Cabang $lokasi): float
    {
        return app(StokService::class)->saldo($lokasi->id, $this->ayam->id);
    }

    private function mintaDanSetujui(string $qty): Permintaan
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris' => [['bahan_baku_id' => $this->ayam->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();

        $permintaan = Permintaan::latest('id')->first();
        $this->app->forgetScopedInstances();

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
                'keputusan' => [$permintaan->detail()->first()->id => ['aksi' => 'setujui', 'qty' => $qty]],
            ])
            ->assertRedirect(route('admin-utama.kiriman.buat', $permintaan));

        return $permintaan;
    }

    public function test_permintaan_manual_dan_otomatis_tanpa_prioritas(): void
    {
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->ayam->id, 'minimum' => 5000]);
        app(PermintaanService::class)->buatOtomatis();

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.permintaan.simpan'), [
                'baris'     => [['bahan_baku_id' => $this->ayam->id, 'qty' => '2']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('permintaan', 'prioritas'));
        $this->assertEqualsCanonicalizing([Permintaan::SUMBER_MANUAL, Permintaan::SUMBER_OTOMATIS], Permintaan::pluck('sumber')->all());
        $this->assertSame(2, Permintaan::count());
    }

    public function test_admin_utama_mengirim_langsung_setelah_menyetujui_dan_cabang_menerima_sesuai(): void
    {
        $permintaan = $this->mintaDanSetujui('4');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.kiriman.buat', $permintaan))
            ->assertOk()
            ->assertSee('Kirim ke Cabang Jati')
            ->assertSee('action="'.route('admin-utama.kiriman.simpan', $permintaan).'"', false);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.kiriman'))
            ->assertOk()
            ->assertSee($permintaan->nomor)
            ->assertSee('id="nav-admin-utama.kiriman"', false);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.kiriman.simpan', $permintaan), ['jumlah' => [$permintaan->detail()->first()->id => '4']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin-utama.kiriman.lihat', Kiriman::first()));

        $kiriman = Kiriman::first();
        $this->assertSame($this->pusat->id, $kiriman->dari_cabang_id);
        $this->assertSame(16000.0, $this->saldo($this->pusat));
        $this->assertSame(0.0, $this->saldo($this->cabang));

        $this->app->forgetScopedInstances();
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang.lihat', $kiriman))
            ->assertOk()
            ->assertSeeInOrder(['Ayam fillet', '4 kg', 'Sesuai Semua']);

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.terima-barang.simpan', $kiriman), ['kesesuaian' => 'sesuai'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses', "Kiriman {$kiriman->nomor} diterima sesuai. Stok Cabang Jati sudah bertambah.");

        $this->assertSame(4000.0, $this->saldo($this->cabang));
        $this->assertSame(Kiriman::STATUS_SELESAI, $kiriman->fresh()->status);
    }

    public function test_cabang_menyatakan_ada_yang_beda_dan_stok_bertambah_sebanyak_yang_diterima(): void
    {
        $permintaan = $this->mintaDanSetujui('4');
        $this->actingAs($this->adminUtama)->post(route('admin-utama.kiriman.simpan', $permintaan), ['jumlah' => [$permintaan->detail()->first()->id => '4']]);
        $kiriman = Kiriman::first();
        $detail = $kiriman->detail()->first();

        $this->app->forgetScopedInstances();
        $this->actingAs($this->adminCabang)
            ->post(route('cabang.terima-barang.simpan', $kiriman), [
                'kesesuaian' => 'tidak_sesuai',
                'terima'     => [$detail->id => ['qty' => '3,5', 'kondisi' => 'baik', 'catatan' => '']],
            ])
            ->assertSessionHas('galat', 'Tulis penjelasan untuk Ayam fillet: jumlah diterima berbeda dari yang dikirim.');
        $this->assertSame(0.0, $this->saldo($this->cabang));

        $this->post(route('cabang.terima-barang.simpan', $kiriman), [
            'kesesuaian' => 'tidak_sesuai',
            'terima'     => [$detail->id => ['qty' => '4,5', 'kondisi' => 'baik', 'catatan' => 'Lebih']],
        ])->assertSessionHas('galat');
        $this->assertSame(0.0, $this->saldo($this->cabang));

        $this->post(route('cabang.terima-barang.simpan', $kiriman), [
            'kesesuaian' => 'tidak_sesuai',
            'terima'     => [$detail->id => ['qty' => '3,5', 'kondisi' => 'baik', 'catatan' => 'Setengah kg tertinggal']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3500.0, $this->saldo($this->cabang));
        $this->assertSame(Kiriman::STATUS_DICHECKLIST, $kiriman->fresh()->status);

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.terima-barang.simpan', $kiriman), ['kesesuaian' => 'tidak_sesuai'])
            ->assertSessionHasErrors('terima');
    }

    public function test_detail_permintaan_disetujui_punya_tombol_kirim_sekarang(): void
    {
        $permintaan = $this->mintaDanSetujui('2');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan.permintaan', $permintaan))
            ->assertOk()
            ->assertSee('href="'.route('admin-utama.kiriman.buat', $permintaan).'"', false)
            ->assertSee('Kirim Sekarang');
    }

    public function test_web_order_tidak_ada_lagi_dan_semua_cabang_mencatat_sendiri(): void
    {
        $this->assertArrayNotHasKey('web_order', app(KontrolSistemService::class)->daftarModul());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('penjualan_masuk'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('cabang', 'sumber_penjualan'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('menu', 'kode'));

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertDontSee('Web Order');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.lokasi.ubah', $this->cabang))
            ->assertOk()
            ->assertDontSee('name="sumber_penjualan"', false);

        $this->postJson('/api/v1/penjualan', [], ['Authorization' => 'Bearer apa-saja'])->assertNotFound();
    }

    public function test_penjualan_cabang_langsung_memotong_stok_bahan_sesuai_resep(): void
    {
        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->ayam->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);
        $menu = Menu::create(['nama' => 'Chicken katsu', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Nasi'])->id]);
        Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $this->ayam->id, 'jumlah' => 150, 'berlaku_sejak' => now()->toDateString()]);

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.penjualan.simpan'), ['tanggal' => now()->toDateString(), 'porsi' => [$menu->id => 10]])
            ->assertSessionHasNoErrors();

        $this->assertSame(3500.0, $this->saldo($this->cabang));
    }

    public function test_halaman_pantauan_diperbarui_otomatis_dan_form_tidak(): void
    {
        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->ayam->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSee('<meta name="jeda-segar" content="'.config('stokku.jeda_segar_detik').'">', false)
            ->assertSee('class="isi-segar" id="isi-segar" data-segar', false)
            ->assertSee('id="tombol-lonceng" data-segar', false);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('id="stok-baris-'.$this->ayam->id.'" data-segar="baris"', false)
            ->assertDontSee('id="isi-segar"', false);

        foreach (['cabang.penjualan', 'cabang.stok.mutasi', 'cabang.barang-keluar', 'cabang.opname', 'cabang.permintaan', 'cabang.terima-barang'] as $rute) {
            $this->get(route($rute))->assertOk()->assertSee('class="isi-segar" id="isi-segar" data-segar', false);
        }

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.permintaan.buat'))
            ->assertOk()
            ->assertDontSee('id="isi-segar"', false);
    }

    public function test_stok_dan_kiriman_tanpa_lokasi_diarahkan_ke_halaman_lokasi(): void
    {
        Cabang::query()->update(['aktif' => false]);
        $admin = User::where('role', User::ROLE_ADMIN_UTAMA)->first() ?? User::factory()->adminUtama()->create();

        $this->actingAs($admin)->get(route('admin-utama.stok'))
            ->assertRedirect(route('admin-utama.lokasi'))
            ->assertSessionHas('galat');

        $this->get(route('admin-utama.kiriman'))
            ->assertRedirect(route('admin-utama.lokasi'))
            ->assertSessionHas('galat');
    }
}
