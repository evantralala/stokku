<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\PenjualanHarian;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenjualanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Cabang $cabang;
    private BahanBaku $mie;
    private BahanBaku $nori;
    private Menu $shoyu;
    private Menu $ocha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(21, 0));

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kategori = Kategori::create(['nama' => 'Kering']);
        $buat = fn (string $kode, string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
        $this->mie = $buat('BB-0001', 'Mie ramen');
        $this->nori = $buat('BB-0002', 'Nori');

        $ramen = KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);
        $minuman = KategoriMenu::create(['nama' => 'Minuman', 'urutan' => 2]);
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);
        $this->ocha = Menu::create(['nama' => 'Ocha', 'kategori_menu_id' => $minuman->id]);

        $resep = fn (BahanBaku $b, float $jumlah, string $sejak) => Resep::create([
            'menu_id' => $this->shoyu->id, 'bahan_baku_id' => $b->id, 'jumlah' => $jumlah, 'berlaku_sejak' => $sejak,
        ]);
        $resep($this->mie, 150, now()->subDays(10)->toDateString());
        $resep($this->nori, 2, now()->subDays(10)->toDateString());
        $resep($this->mie, 180, now()->toDateString());

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 5000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 100, 'diperbarui_pada' => now()],
        ]);
    }

    private function simpan(string $tanggal, array $porsi)
    {
        return $this->actingAs($this->admin)->post(route('cabang.penjualan.simpan'), ['tanggal' => $tanggal, 'porsi' => $porsi]);
    }

    private function saldo(BahanBaku $b): float
    {
        return app(StokService::class)->saldo($this->cabang->id, $b->id);
    }

    public function test_penjualan_hari_ini_memakai_resep_yang_berlaku_hari_ini_dan_mengurangi_stok(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10', $this->ocha->id => '4'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $penjualan = PenjualanHarian::first();
        $this->assertSame(2, $penjualan->detail()->count());

        $dokumen = $penjualan->barangKeluar;
        $this->assertSame(BarangKeluar::JENIS_PEMAKAIAN, $dokumen->jenis);
        $this->assertSame(BarangKeluar::STATUS_DISETUJUI, $dokumen->status);

        // Resep hari ini: mie 180 (versi baru), nori tidak ada di versi baru.
        $this->assertSame(5000.0 - 1800.0, $this->saldo($this->mie));
        $this->assertSame(100.0, $this->saldo($this->nori));
        $this->assertSame(0, StokMutasi::where('jenis', StokMutasi::JENIS_PAKAI_DAPUR)->where('status', '!=', StokMutasi::STATUS_SAH)->count());
    }

    public function test_penjualan_kemarin_memakai_resep_lama_dan_stok_boleh_minus(): void
    {
        $this->simpan(now()->subDay()->toDateString(), [$this->shoyu->id => '100'])->assertSessionHasNoErrors();

        // Resep lama: mie 150, nori 2 per porsi.
        $this->assertSame(5000.0 - 15000.0, $this->saldo($this->mie));
        $this->assertSame(100.0 - 200.0, $this->saldo($this->nori));

        $takaran = PenjualanHarian::first()->detail()->first()->takaran_resep_json;
        $this->assertCount(2, $takaran);
        $this->assertSame('gram', $takaran[0]['satuan']);
    }

    public function test_satu_catatan_per_hari_dan_form_mengarahkan_ke_ubah(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '1']);

        $this->simpan(now()->toDateString(), [$this->shoyu->id => '1'])->assertSessionHas('galat');
        $this->assertSame(1, PenjualanHarian::count());

        $this->actingAs($this->admin)
            ->get(route('cabang.penjualan.catat'))
            ->assertRedirect(route('cabang.penjualan.ubah', PenjualanHarian::first()));
    }

    public function test_validasi_tanggal_dan_porsi_kosong(): void
    {
        $this->simpan(now()->addDay()->toDateString(), [$this->shoyu->id => '1'])->assertSessionHasErrors('tanggal');
        $this->simpan(now()->subDays(8)->toDateString(), [$this->shoyu->id => '1'])->assertSessionHasErrors('tanggal');
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '', $this->ocha->id => '0'])->assertSessionHas('galat');

        $this->assertSame(0, PenjualanHarian::count());
    }

    public function test_ubah_menyesuaikan_stok_lewat_mutasi_pembalik(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10']);
        $penjualan = PenjualanHarian::first();

        $this->actingAs($this->admin)
            ->put(route('cabang.penjualan.perbarui', $penjualan), [
                'tanggal' => now()->toDateString(),
                'porsi'   => [$this->shoyu->id => '4', $this->ocha->id => '2'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(5000.0 - 720.0, $this->saldo($this->mie));
        $this->assertSame(4, $penjualan->detail()->where('menu_id', $this->shoyu->id)->value('porsi'));
        // Mutasi lama tidak diubah: -1800, +1800 (pembalik), -720.
        $this->assertSame(['-1800.000', '1800.000', '-720.000'], StokMutasi::orderBy('id')->pluck('jumlah')->all());

        $this->actingAs($this->admin)
            ->put(route('cabang.penjualan.perbarui', $penjualan), [
                'tanggal' => now()->toDateString(),
                'porsi'   => [$this->shoyu->id => '10'],
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(5000.0 - 1800.0, $this->saldo($this->mie));
        $this->assertSame(1, $penjualan->detail()->count());
    }

    public function test_tidak_bisa_diubah_setelah_lewat_batas(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10']);
        $penjualan = PenjualanHarian::first();

        $this->travel(2)->days();

        $this->actingAs($this->admin)
            ->get(route('cabang.penjualan.ubah', $penjualan))
            ->assertRedirect(route('cabang.penjualan.lihat', $penjualan))
            ->assertSessionHas('galat');
    }

    public function test_ditolak_saat_stok_cabang_sedang_opname(): void
    {
        $this->cabang->update(['opname_aktif' => true]);

        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10'])->assertSessionHas('galat');

        $this->assertSame(0, PenjualanHarian::count());
        $this->assertSame(5000.0, $this->saldo($this->mie));
    }

    public function test_detail_menampilkan_porsi_dan_pemakaian_serta_hanya_untuk_admin_cabang(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10', $this->ocha->id => '4']);
        $penjualan = PenjualanHarian::first();

        $this->actingAs($this->admin)
            ->get(route('cabang.penjualan.lihat', $penjualan))
            ->assertOk()
            ->assertSee('14 porsi')
            ->assertSee('1.800 gram')
            ->assertSee('Tanpa Resep');

        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->actingAs(User::factory()->adminPusat($pusat->id)->create())
            ->get(route('cabang.penjualan'))
            ->assertForbidden();
    }

    public function test_daftar_dan_detail_penjualan_ringkas_per_kategori(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10', $this->ocha->id => '4']);
        $penjualan = PenjualanHarian::first();

        $this->actingAs($this->admin)
            ->get(route('cabang.penjualan'))
            ->assertOk()
            ->assertSee(route('cabang.penjualan.lihat', $penjualan), false)
            ->assertSee('14 porsi')
            ->assertSee('2 menu')
            ->assertSee('Lihat Hari Ini');

        $this->get(route('cabang.penjualan.lihat', $penjualan))
            ->assertOk()
            ->assertSeeInOrder(['Ramen', 'Shoyu Ramen', '10', 'Minuman', 'Ocha', '4']);
    }

    public function test_form_penjualan_gaya_kasir_dengan_menu_tanpa_resep_terkunci(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.penjualan.catat'))
            ->assertOk()
            ->assertSee('<form class="panel form-kasir" method="POST" novalidate data-penjualan data-popup-isi', false)
            ->assertSee('data-cari-menu', false)
            ->assertSeeInOrder(['data-menu-kartu data-cari="shoyu ramen"', 'data-isian-jumlah', 'name="porsi['.$this->shoyu->id.']"'], false)
            ->assertSeeInOrder(['class="kartu-menu tanpa-resep"', 'Belum Ada Resep', 'name="porsi['.$this->ocha->id.']"', 'disabled="disabled"'], false);

        $this->actingAs($this->admin)
            ->get(route('cabang.penjualan'))
            ->assertSee('href="'.route('cabang.penjualan.catat').'"', false)
            ->assertSee('data-popup="panjang"', false);
    }

    public function test_menu_tanpa_resep_yang_sudah_terisi_tetap_bisa_diubah(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '2', $this->ocha->id => '3']);
        $penjualan = \App\Models\PenjualanHarian::first();

        $html = $this->actingAs($this->admin)->get(route('cabang.penjualan.ubah', $penjualan))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="porsi\['.$this->ocha->id.'\]"[^>]*value="3"(?![^>]*disabled)/', $html);
    }

    public function test_admin_utama_membetulkan_penjualan_cabang_setelah_batas_waktu_dan_cabang_diberi_tahu(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10']);
        $penjualan = PenjualanHarian::first();
        $utama = User::factory()->adminUtama()->create(['nama' => 'Bos Utama']);

        $this->travel(5)->days();

        $this->actingAs($utama)->get(route('admin-utama.penjualan'))
            ->assertOk()->assertSee('Cabang Jati')->assertSee(route('admin-utama.penjualan.lihat', $penjualan));
        $this->actingAs($utama)->get(route('admin-utama.penjualan.ubah', $penjualan))->assertOk()->assertSee('Shoyu Ramen');
        $this->actingAs($utama)
            ->post(route('admin-utama.penjualan.perkiraan', ['penjualan' => $penjualan->id]), ['porsi' => [$this->shoyu->id => '4']])
            ->assertOk();

        $this->actingAs($utama)
            ->put(route('admin-utama.penjualan.perbarui', $penjualan), ['porsi' => [$this->shoyu->id => '4']])
            ->assertRedirect(route('admin-utama.penjualan.lihat', $penjualan))
            ->assertSessionHasNoErrors();

        $penjualan->refresh();
        $this->assertSame(4, $penjualan->detail()->value('porsi'));
        $this->assertSame($this->admin->id, $penjualan->dicatat_oleh);
        $this->assertSame(5000.0 - 720.0, $this->saldo($this->mie));

        $notif = \App\Models\Notifikasi::where('user_id', $this->admin->id)->latest('id')->first();
        $this->assertStringContainsString('diubah Admin Utama', $notif->judul);
        $this->assertStringContainsString('Shoyu Ramen 10 → 4', $notif->keterangan);

        $this->actingAs($utama)->get(route('admin-utama.penjualan.lihat', $penjualan))
            ->assertOk()->assertSee('Terakhir diubah')->assertSee('Riwayat Perubahan')->assertSee('Bos Utama');

        $this->actingAs($this->admin)->get(route('cabang.penjualan.lihat', $penjualan))
            ->assertOk()->assertSee('Terakhir diubah')->assertSee('10 porsi')->assertSee('4 porsi')
            ->assertDontSee(route('cabang.penjualan.ubah', $penjualan));
    }

    public function test_cabang_mengubah_penjualan_admin_utama_diberi_tahu(): void
    {
        $utama = User::factory()->adminUtama()->create();
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10']);
        $penjualan = PenjualanHarian::first();

        $this->actingAs($this->admin)
            ->put(route('cabang.penjualan.perbarui', $penjualan), ['tanggal' => now()->toDateString(), 'porsi' => [$this->shoyu->id => '6']])
            ->assertSessionHasNoErrors();

        $notif = \App\Models\Notifikasi::where('user_id', $utama->id)->latest('id')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Cabang Jati', $notif->judul);
        $this->assertSame(route('admin-utama.penjualan.lihat', $penjualan, false), $notif->tautan);
    }

    public function test_catatan_penjualan_semua_cabang_hanya_untuk_admin_utama(): void
    {
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10']);
        $penjualan = PenjualanHarian::first();

        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang', 'jenis' => Cabang::JENIS_PUSAT]);
        $adminPusat = User::factory()->adminPusat($pusat->id)->create();
        $this->actingAs($adminPusat)->get(route('admin-utama.penjualan'))->assertForbidden();
        $this->actingAs($adminPusat)->put(route('admin-utama.penjualan.perbarui', $penjualan), ['porsi' => [$this->shoyu->id => '1']])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin-utama.penjualan.lihat', $penjualan))->assertForbidden();

        $this->actingAs(User::factory()->adminUtama()->create())->get(route('admin-utama.penjualan.lihat', $penjualan))->assertOk();
    }

    public function test_dashboard_cabang_mengingatkan_hari_yang_belum_dicatat(): void
    {
        $this->cabang->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->simpan(now()->subDays(2)->toDateString(), [$this->shoyu->id => '1']);

        $html = $this->actingAs($this->admin)->get(route('cabang.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('penjualan-terlewat', $html);
        $this->assertStringContainsString(route('cabang.penjualan.catat', ['tanggal' => now()->subDay()->toDateString()]), $html);
        $this->assertStringNotContainsString(route('cabang.penjualan.catat', ['tanggal' => now()->subDays(2)->toDateString()]), $html);
        $this->assertStringContainsString(route('cabang.penjualan.catat', ['tanggal' => now()->subDays(7)->toDateString()]), $html);
        $this->assertStringNotContainsString(route('cabang.penjualan.catat', ['tanggal' => now()->subDays(8)->toDateString()]), $html);
    }

    public function test_sebelum_jam_ganti_hari_penjualan_masih_dianggap_kemarin(): void
    {
        $this->travelTo(now()->addDay()->setTime(2, 0));

        $this->actingAs($this->admin)->get(route('cabang.penjualan.catat'))
            ->assertOk()->assertSee('value="'.now()->subDay()->toDateString().'"', false);

        \App\Models\Pengaturan::where('kunci', 'jam_ganti_hari_penjualan')->update(['nilai' => '00:00']);
        \Illuminate\Support\Facades\Cache::flush();

        $this->actingAs($this->admin)->get(route('cabang.penjualan.catat'))
            ->assertOk()->assertSee('value="'.now()->toDateString().'"', false);
    }

    public function test_admin_pusat_yang_diberi_catatan_penjualan_bisa_membetulkan_dan_admin_utama_diberi_tahu(): void
    {
        $utama = User::factory()->adminUtama()->create();
        $pusat = User::factory()->adminPusat(Cabang::create(['kode' => 'GP', 'nama' => 'Gudang', 'jenis' => Cabang::JENIS_PUSAT])->id)->create(['nama' => 'Admin Gudang']);
        $this->simpan(now()->toDateString(), [$this->shoyu->id => '10']);
        $penjualan = PenjualanHarian::first();
        $this->travel(3)->days();

        $this->actingAs($pusat)->get(route('admin-utama.penjualan.ubah', $penjualan))->assertForbidden();

        \App\Models\KontrolSistem::create(['kunci' => 'akses.admin_pusat.utama-penjualan.lihat', 'nilai' => '1']);

        $this->actingAs($pusat)
            ->put(route('admin-utama.penjualan.perbarui', $penjualan), ['porsi' => [$this->shoyu->id => '7']])
            ->assertSessionHasNoErrors()->assertSessionHas('sukses');

        $this->assertSame(7, $penjualan->detail()->value('porsi'));
        $this->assertSame($this->admin->id, $penjualan->fresh()->dicatat_oleh);
        $this->assertStringContainsString('diubah Admin Pusat', \App\Models\Notifikasi::where('user_id', $this->admin->id)->latest('id')->value('judul'));
        $this->assertStringContainsString('Cabang Jati', \App\Models\Notifikasi::where('user_id', $utama->id)->latest('id')->value('judul'));
    }
}
