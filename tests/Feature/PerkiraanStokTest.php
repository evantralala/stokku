<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\PantauanStokService;
use App\Services\PenjualanService;
use App\Services\PerkiraanStokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Perkiraan stok otomatis sebelum penjualan memotong stok (form penjualan cabang dan cek impor penjualan). */
class PerkiraanStokTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private Cabang $cabangLain;
    private User $adminCabang;
    private User $adminUtama;
    private BahanBaku $mie;
    private BahanBaku $nori;
    private Menu $shoyu;
    private Menu $teh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(20, 0));

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->cabangLain = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();
        $this->adminUtama = User::factory()->adminUtama()->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kering = Kategori::create(['nama' => 'Kering', 'urutan' => 1]);
        $this->mie = BahanBaku::create(['nama' => 'Mie ramen', 'kategori_id' => $kering->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5]);
        $this->nori = BahanBaku::create(['nama' => 'Nori', 'kategori_id' => $kering->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id, 'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5]);

        $ramen = KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);
        $this->teh = Menu::create(['nama' => 'Teh Tawar', 'kategori_menu_id' => $ramen->id]);
        $sejak = now()->subDays(10)->toDateString();
        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->mie->id, 'jumlah' => 150, 'berlaku_sejak' => $sejak]);
        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => 2, 'berlaku_sejak' => $sejak]);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 1000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 10, 'diperbarui_pada' => now()],
        ]);
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'minimum' => 800]);
    }

    private function saldo(BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $this->cabang->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    public function test_perkiraan_menghitung_sisa_dan_status_tanpa_mengubah_stok(): void
    {
        $hasil = app(PerkiraanStokService::class)->penjualan($this->cabang, [[
            'tanggal' => now()->toDateString(),
            'porsi'   => [$this->shoyu->id => 6, $this->teh->id => 3],
        ]]);

        $this->assertSame(['Nori', 'Mie ramen'], $hasil->map(fn ($r) => $r['bahan']->nama)->all());
        $this->assertSame([10.0, 12.0, -2.0, PantauanStokService::STATUS_MINUS], [$hasil[0]['stok'], $hasil[0]['pakai'], $hasil[0]['sisa'], $hasil[0]['status']]);
        $this->assertSame([1000.0, 900.0, 100.0, PantauanStokService::STATUS_KURANG], [$hasil[1]['stok'], $hasil[1]['pakai'], $hasil[1]['sisa'], $hasil[1]['status']]);

        $this->assertSame(1000.0, $this->saldo($this->mie));
        $this->assertSame(0, StokMutasi::count());
    }

    public function test_penjualan_yang_diubah_mengembalikan_pemakaian_lama_dulu(): void
    {
        $penjualan = app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->toDateString(), [$this->shoyu->id => 2]);
        $this->assertSame(700.0, $this->saldo($this->mie));

        $hasil = app(PerkiraanStokService::class)->penjualan($this->cabang, [[
            'tanggal' => now()->toDateString(),
            'porsi'   => [$this->shoyu->id => 3],
            'ganti'   => $penjualan,
        ]])->keyBy(fn ($r) => $r['bahan']->nama);

        $this->assertSame([700.0, 300.0, 450.0, 550.0], [
            $hasil['Mie ramen']['stok'], $hasil['Mie ramen']['kembali'], $hasil['Mie ramen']['pakai'], $hasil['Mie ramen']['sisa'],
        ]);
    }

    public function test_form_penjualan_menampilkan_perkiraan_dan_alamat_hitung_ulang(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.penjualan.catat'))
            ->assertOk()
            ->assertSee('data-perkiraan-url="'.route('cabang.penjualan.perkiraan').'"', false)
            ->assertSee('Isi porsi menu yang punya resep untuk melihat perkiraan stok');

        $penjualan = app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->toDateString(), [$this->shoyu->id => 2]);

        $this->get(route('cabang.penjualan.ubah', $penjualan))
            ->assertOk()
            ->assertSee(route('cabang.penjualan.perkiraan', ['penjualan' => $penjualan->id]), false)
            ->assertSee('Perkiraan Stok Setelah Disimpan')
            ->assertSee('pemakaian lama +0,3 kg')
            ->assertSee('Sisa 0,7 kg');
    }

    public function test_hitung_ulang_saat_porsi_diisi_mengembalikan_potongan_html(): void
    {
        $this->actingAs($this->adminCabang)
            ->post(route('cabang.penjualan.perkiraan'), ['tanggal' => now()->toDateString(), 'porsi' => [$this->shoyu->id => '6', $this->teh->id => '']])
            ->assertOk()
            ->assertDontSee('<html', false)
            ->assertSee('data-ringkas="2 bahan terpotong dari stok, 1 bahan akan minus, 1 bahan akan habis atau menipis."', false)
            ->assertSeeInOrder(['Nori', 'Sisa -2 gram', 'Habis', 'Minus', 'Mie ramen', 'Sisa 0,1 kg', 'Menipis', 'Di Bawah Minimum']);

        $lain = app(PenjualanService::class)->terapkanImpor($this->cabangLain, now()->toDateString(), [$this->shoyu->id => 1], $this->adminUtama);

        $this->post(route('cabang.penjualan.perkiraan', ['penjualan' => $lain->id]), ['porsi' => [$this->shoyu->id => '1']])
            ->assertOk()
            ->assertDontSee('pemakaian lama');

        $this->assertSame(1000.0, $this->saldo($this->mie));
    }

    public function test_cek_impor_penjualan_menampilkan_perkiraan_per_cabang(): void
    {
        $file = UploadedFile::fake()->createWithContent('jual.csv', "tanggal,lokasi,menu,porsi\n,JTI,Shoyu Ramen,6\n,JTI,Teh Tawar,2\n");

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.excel.pratinjau', 'penjualan'), ['file' => $file])
            ->assertOk()
            ->assertSee('Perkiraan Stok Cabang Jati')
            ->assertSee('Sisa -2 gram')
            ->assertDontSee('Perkiraan Stok Cabang Kenanga');

        $this->assertSame(10.0, $this->saldo($this->nori));
    }
}
