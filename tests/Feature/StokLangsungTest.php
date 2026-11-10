<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Notifikasi;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\TambahStok;
use App\Models\User;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tambah stok langsung (Admin Utama dan Admin Pusat), lembar stock opname PDF, dan riwayat masuk/keluar & pemakaian harian. */
class StokLangsungTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $cabang;
    private User $adminUtama;
    private User $adminCabang;
    private User $adminPusat;
    private BahanBaku $beras;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $pokok = Kategori::create(['nama' => 'Bahan pokok', 'urutan' => 1]);
        $kering = Kategori::create(['nama' => 'Kering', 'urutan' => 2]);

        $this->beras = BahanBaku::create([
            'nama' => 'Beras Jepang', 'kategori_id' => $pokok->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => $kering->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 500, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
        ]);
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->beras->id, 'minimum' => 10000]);

        $this->izinkanUbahStokPusat();
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    public function test_admin_pusat_dan_admin_utama_tambah_stok_langsung_tanpa_persetujuan_dan_tercatat(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('pusat.stok.tambah', ['bahan' => $this->beras->id]))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('Stok sekarang 8 kg');

        $this->post(route('pusat.stok.tambah.simpan'), ['bahan_baku_id' => $this->beras->id, 'jumlah' => '2,5'])
            ->assertRedirect(route('pusat.stok'))
            ->assertSessionHas('sukses');

        $this->assertSame(10500.0, $this->saldo($this->pusat, $this->beras));
        $dokumen = TambahStok::sole();
        $this->assertNull($dokumen->keterangan);
        $mutasi = StokMutasi::where('dokumen_tipe', TambahStok::DOKUMEN_TIPE)->sole();
        $this->assertSame(StokMutasi::JENIS_TAMBAH_LANGSUNG, $mutasi->jenis);
        $this->assertSame(StokMutasi::STATUS_SAH, $mutasi->status);
        $this->assertSame(1, Notifikasi::where('user_id', $this->adminUtama->id)->count());

        $this->get(route('pusat.stok.riwayat', $this->beras))->assertSee('Tambah Stok')->assertSee($dokumen->nomor);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.tambah.simpan', ['lokasi' => $this->pusat->id]), [
                'bahan_baku_id' => $this->nori->id, 'jumlah' => '100', 'keterangan' => 'Beli di toko',
            ])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]));

        $this->assertSame(100.0, $this->saldo($this->pusat, $this->nori));
        $this->assertSame(500.0, $this->saldo($this->cabang, $this->nori));
        $this->assertSame(1, Notifikasi::count());

        $this->post(route('admin-utama.stok.tambah.simpan', ['lokasi' => $this->pusat->id]), ['bahan_baku_id' => $this->nori->id, 'jumlah' => '0'])
            ->assertSessionHasErrors('jumlah');
    }

    public function test_tambah_stok_ditolak_saat_lokasi_sedang_opname(): void
    {
        $this->pusat->update(['opname_aktif' => true]);

        $this->actingAs($this->adminPusat)
            ->post(route('pusat.stok.tambah.simpan'), ['bahan_baku_id' => $this->beras->id, 'jumlah' => '1'])
            ->assertSessionHas('galat');

        $this->assertSame(0, TambahStok::count());
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_lembar_opname_pdf_per_kategori(): void
    {
        $this->actingAs($this->adminCabang)->get(route('cabang.stok.lembar-opname'))->assertOk()->assertSee('Unduh PDF');

        $semua = $this->get(route('cabang.stok.lembar-opname.unduh'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $isi = $semua->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $isi);
        $this->assertStringContainsString('(Beras Jepang) Tj', $isi);
        $this->assertStringContainsString('(Nori) Tj', $isi);
        $this->assertStringContainsString('(8) Tj', $isi);
        $this->assertStringEndsWith('%%EOF', $isi);

        $kering = $this->get(route('cabang.stok.lembar-opname.unduh', ['kategori' => $this->nori->kategori_id]))->getContent();
        $this->assertStringContainsString('(Nori) Tj', $kering);
        $this->assertStringNotContainsString('(Beras Jepang) Tj', $kering);
    }

    public function test_riwayat_masuk_keluar_dan_pemakaian_harian(): void
    {
        $menu = Menu::create(['nama' => 'Onigiri', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Nasi', 'urutan' => 1])->id]);
        Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $this->beras->id, 'jumlah' => 100, 'berlaku_sejak' => now()->subDays(5)->toDateString()]);
        app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->subDay()->toDateString(), [$menu->id => 12]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.tambah.simpan', ['lokasi' => $this->cabang->id]), ['bahan_baku_id' => $this->nori->id, 'jumlah' => '50']);

        $this->actingAs($this->adminCabang)->get(route('cabang.stok'))->assertSee(route('cabang.stok.mutasi'), false);

        $this->get(route('cabang.stok.mutasi', ['tampil' => 'transaksi']))
            ->assertOk()
            ->assertSee('Pemakaian Dapur')
            ->assertSee('Tambah Stok')
            ->assertSee('-1,2 kg')
            ->assertSee('+50 gram');

        $this->get(route('cabang.stok.mutasi', ['arah' => 'keluar']))
            ->assertSee('Pemakaian Dapur')
            ->assertDontSee('+50 gram');

        $this->get(route('cabang.stok.mutasi', ['tampil' => 'harian']))
            ->assertOk()
            ->assertSee(now()->subDay()->translatedFormat('j M Y'))
            ->assertSee('1,2 kg')
            ->assertDontSee('>'.$this->nori->nama.'</a>', false);
    }
}
