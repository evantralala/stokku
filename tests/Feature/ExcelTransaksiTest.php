<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\PenjualanHarian;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use Tests\TestCase;

/** Impor & ekspor Excel untuk resep, penjualan harian, stok minimum, dan barang masuk pusat. */
class ExcelTransaksiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $mie;
    private BahanBaku $nori;
    private Menu $shoyu;
    private Menu $ocha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Kering', 'urutan' => 1]);
        $this->mie = BahanBaku::create([
            'nama' => 'Mie ramen', 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);

        $ramen = KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);
        $this->ocha = Menu::create(['nama' => 'Ocha', 'kategori_menu_id' => $ramen->id]);

        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->mie->id, 'jumlah' => 150, 'berlaku_sejak' => now()->subDays(10)->toDateString()]);
        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => 2, 'berlaku_sejak' => now()->subDays(10)->toDateString()]);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 5000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 100, 'diperbarui_pada' => now()],
        ]);
    }

    private function csv(string $judul, array $baris): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('data.csv', $judul."\n".implode("\n", $baris)."\n");
    }

    private function pratinjau(string $master, UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.pratinjau', $master), ['file' => $file])->assertOk();
    }

    private function simpan(string $master, TestResponse $pratinjau): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.simpan', $master), ['kunci' => $pratinjau->viewData('kunci')]);
    }

    private function statusBaris(TestResponse $pratinjau): array
    {
        return $pratinjau->viewData('baris')->pluck('status', 'baris')->sortKeys()->all();
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    private function bukaExcel(TestResponse $respons): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return IOFactory::load($berkas)->getSheet(0)->toArray(null, true, false);
    }

    public function test_tombol_excel_tampil_di_menu_stok_minimum_laporan_penjualan_dan_barang_masuk(): void
    {
        $this->actingAs($this->adminUtama);
        $this->get(route('admin-utama.menu'))->assertOk()
            ->assertSee(route('admin-utama.excel.unggah', 'resep'), false)
            ->assertSee('Impor Resep');
        $this->get(route('admin-utama.stok-minimum'))->assertOk()->assertSee(route('admin-utama.excel.unggah', 'stok-minimum'), false);
        $this->get(route('admin-utama.laporan.penjualan'))->assertOk()->assertSee(route('admin-utama.excel.unggah', 'penjualan'), false);

        $this->actingAs($this->adminPusat)->get(route('pusat.barang-masuk'))->assertOk()
            ->assertSee(route('pusat.barang-masuk.impor'), false)
            ->assertSee(route('pusat.barang-masuk.ekspor'), false);
        $this->get(route('pusat.barang-masuk.impor'))->assertOk()->assertSee(route('pusat.barang-masuk.impor.templat'), false);
        $this->get(route('admin-utama.excel.unggah', 'resep'))->assertForbidden();
    }

    public function test_kolom_berlaku_sejak_di_file_lama_diabaikan_resep_berlaku_hari_ini(): void
    {
        $pratinjau = $this->pratinjau('resep', $this->csv('menu,kategori_menu,bahan,takaran,satuan,berlaku_sejak', [
            'Shoyu Ramen,,Mie ramen,175,gram,'.now()->addWeek()->format('d/m/Y'),
            'Shoyu Ramen,,Nori,2,gram,01/01/2020',
        ]));

        $this->assertSame([2 => 'siap', 3 => 'siap'], $this->statusBaris($pratinjau));
        $this->simpan('resep', $pratinjau)->assertSessionHas('sukses');
        $this->assertSame(now()->toDateString(), $this->shoyu->fresh()->versiResep()->first());
    }

    public function test_impor_resep_membuat_menu_baru_dan_mengganti_resep_yang_berlaku(): void
    {
        $hariIni = now()->toDateString();
        $pratinjau = $this->pratinjau('resep', $this->csv('menu,kategori_menu,bahan,takaran,satuan,berlaku_sejak,catatan', [
            'Shoyu Ramen,,Mie ramen,180,gram,,',
            'Shoyu Ramen,,Nori,3,gram,,',
            'Miso Ramen,Ramen Spesial,Mie ramen,160,gram,,',
            'Teh Tawar,Minuman,,,,,',
            'Tantan,,Mie ramen,150,gram,,',
            'Gyoza,Snack,Kubis,20,gram,,',
            'Gyoza,Snack,Mie ramen,10,gram,,',
            'Ocha,,,,,,',
        ]));

        $this->assertSame([2 => 'siap', 3 => 'siap', 4 => 'siap', 5 => 'siap', 6 => 'salah', 7 => 'salah', 8 => 'salah', 9 => 'dilewati'], $this->statusBaris($pratinjau));

        $this->simpan('resep', $pratinjau)->assertRedirect(route('admin-utama.menu'))->assertSessionHas('sukses');

        $resep = fn (string $tanggal) => $this->shoyu->fresh()->resepBerlaku($tanggal)
            ->mapWithKeys(fn ($r) => [$r->bahanBaku->nama => (float) $r->jumlah])->sortKeys()->all();
        $this->assertSame(['Mie ramen' => 150.0, 'Nori' => 2.0], $resep(now()->subDay()->toDateString()));
        $this->assertSame(['Mie ramen' => 180.0, 'Nori' => 3.0], $resep($hariIni));

        $miso = Menu::where('nama', 'Miso Ramen')->firstOrFail();
        $this->assertSame('Ramen Spesial', $miso->kategoriMenu->nama);
        $this->assertCount(1, $miso->resepBerlaku($hariIni));
        $this->assertCount(0, Menu::where('nama', 'Teh Tawar')->firstOrFail()->resepBerlaku($hariIni));
        $this->assertFalse(Menu::where('nama', 'Gyoza')->exists());
        $this->assertFalse(Menu::where('nama', 'Tantan')->exists());

        $ekspor = $this->bukaExcel($this->get(route('admin-utama.excel.ekspor', 'resep'))->assertOk());
        $this->assertSame(['Menu', 'Kategori Menu', 'Bahan', 'Takaran', 'Satuan'], $ekspor[0]);
        $this->assertTrue(in_array(['Shoyu Ramen', 'Ramen', 'Mie ramen', 180, 'gram'], $ekspor), 'Baris ekspor tidak ditemukan.');
        $this->assertTrue(in_array(['Teh Tawar', 'Minuman', null, null, null], $ekspor), 'Baris ekspor tidak ditemukan.');
    }

    public function test_impor_penjualan_memotong_stok_dan_mengganti_hasil_impor_tanggal_yang_sama(): void
    {
        $kemarin = now()->subDay()->toDateString();

        $pratinjau = $this->pratinjau('penjualan', $this->csv('tanggal,lokasi,menu,porsi', [
            "{$kemarin},JTI,Shoyu Ramen,10",
            "{$kemarin},Cabang Jati,Ocha,4",
            "{$kemarin},GP,Shoyu Ramen,1",
            now()->addDay()->toDateString().',JTI,Shoyu Ramen,1',
            now()->subDays(2)->toDateString().',JTI,Udon,1',
            "{$kemarin},JTI,Ocha,",
        ]));

        $this->assertSame([2 => 'siap', 3 => 'siap', 4 => 'salah', 5 => 'salah', 6 => 'salah', 7 => 'dilewati'], $this->statusBaris($pratinjau));
        $pratinjau->assertSee('belum punya resep');

        $this->simpan('penjualan', $pratinjau)->assertRedirect(route('admin-utama.laporan.penjualan'))->assertSessionHas('sukses');
        $this->assertSame(3500.0, $this->saldo($this->cabang, $this->mie));
        $this->assertSame(80.0, $this->saldo($this->cabang, $this->nori));

        $ganti = $this->pratinjau('penjualan', $this->csv('tanggal,lokasi,menu,porsi', ["{$kemarin},JTI,Shoyu Ramen,4"]));
        $ganti->assertSee('Mengganti penjualan hasil impor sebelumnya');
        $this->simpan('penjualan', $ganti)->assertSessionHas('sukses');

        $this->assertSame(1, PenjualanHarian::count());
        $this->assertSame(4400.0, $this->saldo($this->cabang, $this->mie));
        $this->assertSame([4], PenjualanHarian::first()->detail()->pluck('porsi')->map(fn ($p) => (int) $p)->all());

        $hariIni = $this->pratinjau('penjualan', $this->csv('tanggal,lokasi,menu,porsi', [',JTI,Shoyu Ramen,2']));
        $this->assertSame(now()->toDateString(), $hariIni->viewData('baris')->first()['data']['tanggal']);
        $this->assertSame([2 => 'siap'], $this->statusBaris($hariIni));

        $ekspor = $this->bukaExcel($this->get(route('admin-utama.excel.ekspor', 'penjualan'))->assertOk());
        $this->assertEquals([TanggalExcel::PHPToExcel(now()->subDay()->startOfDay()), 'JTI', 'Shoyu Ramen', 4], $ekspor[1]);
    }

    public function test_impor_penjualan_tidak_menimpa_catatan_admin_cabang(): void
    {
        $kemarin = now()->subDay()->toDateString();
        $adminCabang = User::factory()->adminCabang($this->cabang->id)->create();
        app(\App\Services\PenjualanService::class)->catat($adminCabang, $this->cabang, $kemarin, [$this->shoyu->id => 2]);
        $mie = $this->saldo($this->cabang, $this->mie);

        $pratinjau = $this->actingAs($this->adminUtama)->pratinjau('penjualan', $this->csv('tanggal,lokasi,menu,porsi', [
            "{$kemarin},JTI,Shoyu Ramen,10",
            "{$kemarin},JTI,Ocha,3",
            now()->toDateString().',JTI,Shoyu Ramen,1',
        ]));

        $this->assertSame([2 => 'dilewati', 3 => 'dilewati', 4 => 'siap'], $this->statusBaris($pratinjau));
        $pratinjau->assertSee('sudah dicatat cabang, jadi tidak diganti')->assertSee('cabang 2 porsi, file 10 porsi')->assertSee('cabang 0 porsi, file 3 porsi');

        $this->simpan('penjualan', $pratinjau)->assertSessionHas('sukses');
        $catatanCabang = PenjualanHarian::whereDate('tanggal', $kemarin)->sole();
        $this->assertSame([2], $catatanCabang->detail()->pluck('porsi')->map(fn ($p) => (int) $p)->all());
        $this->assertSame($adminCabang->id, $catatanCabang->dicatat_oleh);
        $this->assertSame(2, PenjualanHarian::count());
        $this->assertSame($mie - 150.0, $this->saldo($this->cabang, $this->mie));

        $this->expectException(\DomainException::class);
        app(\App\Services\PenjualanService::class)->terapkanImpor($this->cabang, $kemarin, [$this->shoyu->id => 9], $this->adminUtama);
    }

    public function test_impor_stok_minimum_per_lokasi_dalam_satuan_beli(): void
    {
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 50]);

        $ekspor = $this->bukaExcel($this->actingAs($this->adminUtama)->get(route('admin-utama.excel.ekspor', 'stok-minimum'))->assertOk());
        $this->assertTrue(in_array(['JTI', 'Nori', 'gram', 50], $ekspor), 'Baris ekspor tidak ditemukan.');
        $this->assertTrue(in_array(['GP', 'Mie ramen', 'kg', null], $ekspor), 'Baris ekspor tidak ditemukan.');

        $pratinjau = $this->pratinjau('stok-minimum', $this->csv('lokasi,bahan,satuan,minimum', [
            'GP,Mie ramen,kg,"2,5"',
            'JTI,Nori,gram,0',
            'JTI,Mie ramen,kg,',
            'XYZ,Nori,gram,1',
            'GP,Mie ramen,kg,3',
        ]));

        $this->assertSame([2 => 'siap', 3 => 'siap', 4 => 'dilewati', 5 => 'salah', 6 => 'salah'], $this->statusBaris($pratinjau));
        $this->simpan('stok-minimum', $pratinjau)->assertRedirect(route('admin-utama.stok-minimum'))->assertSessionHas('sukses');

        $this->assertEquals(2500, (float) BahanBakuMinimum::where('cabang_id', $this->pusat->id)->where('bahan_baku_id', $this->mie->id)->value('minimum'));
        $this->assertFalse(BahanBakuMinimum::where('cabang_id', $this->cabang->id)->exists());
    }

    public function test_impor_barang_masuk_pusat_membuat_dokumen_menunggu_per_tanggal(): void
    {
        $kemarin = now()->subDay()->toDateString();
        $hariIni = now()->toDateString();

        $this->actingAs($this->adminPusat);
        $templat = $this->bukaExcel($this->get(route('pusat.barang-masuk.impor.templat'))->assertOk());
        $this->assertSame(['Tanggal', 'Bahan', 'Jumlah', 'Satuan', 'Keterangan'], $templat[0]);
        $this->assertSame(TanggalExcel::PHPToExcel(now()->startOfDay()), $templat[1][0]);
        $this->assertSame([12, 'Nota 0123'], [$templat[1][2], $templat[1][4]], 'templat berisi contoh data biasa');
        $this->assertSame(['Mie ramen', 'Nori'], array_values(array_filter(array_column(array_slice($templat, 1), 1))), 'contoh memakai bahan yang sudah ada');

        // File dari templat lama (masih ada kolom supplier) tetap terbaca; kolom itu diabaikan.
        $pratinjau = $this->post(route('pusat.barang-masuk.impor.pratinjau'), ['file' => $this->csv('tanggal,bahan,jumlah,satuan,supplier,keterangan', [
            "{$kemarin},Mie ramen,2,kg,Toko Jepang,",
            "{$kemarin},Nori,100,gram,toko jepang,datang pagi",
            "{$hariIni},Mie ramen,1,kg,Toko Jepang,",
            "{$hariIni},Nori,banyak,gram,Toko Jepang,",
            "{$hariIni},Mie ramen,,kg,Toko Jepang,",
        ])])->assertOk();

        $this->assertSame([2 => 'siap', 3 => 'siap', 4 => 'salah', 5 => 'salah', 6 => 'dilewati'], $this->statusBaris($pratinjau));
        $this->assertSame([[], []], $pratinjau->viewData('baris')->whereIn('baris', [2, 3])->sortBy('baris')->pluck('betul')->values()->all(),
            'satuan sama dengan satuan bahan tidak ditandai Dibetulkan');

        $this->post(route('pusat.barang-masuk.impor.simpan'), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertRedirect(route('pusat.barang-masuk'))
            ->assertSessionHas('sukses');

        $dokumen = BarangMasuk::with('detail')->sole();
        $this->assertSame(BarangMasuk::STATUS_MENUNGGU, $dokumen->status);
        $this->assertSame($kemarin, $dokumen->tanggal->toDateString());
        $this->assertEquals([2000, 100], $dokumen->detail->pluck('qty')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(0, StokMutasi::sah()->count());
        $this->assertSame(0.0, $this->saldo($this->pusat, $this->mie));

        $this->actingAs($this->adminUtama)->post(route('pusat.barang-masuk.impor.pratinjau'), [
            'file' => $this->csv('tanggal,bahan,jumlah,supplier', ["{$kemarin},Mie ramen,1,Toko Jepang"]),
        ])->assertForbidden();
    }
}
