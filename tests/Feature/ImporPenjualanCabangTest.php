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
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Admin Cabang mengimpor penjualan harian lewat Excel; hasilnya sama dengan form penjualan. */
class ImporPenjualanCabangTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private User $adminUtama;
    private User $adminCabang;
    private BahanBaku $mie;
    private Menu $shoyu;
    private Menu $miso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(21, 0));
        Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $this->mie = BahanBaku::create([
            'nama' => 'Mie Ramen', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $ramen = KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);
        $this->miso = Menu::create(['nama' => 'Miso Ramen', 'kategori_menu_id' => $ramen->id]);

        foreach ([$this->shoyu, $this->miso] as $menu) {
            Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $this->mie->id, 'jumlah' => 100, 'berlaku_sejak' => now()->subDays(10)->toDateString()]);
        }

        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);
    }

    private function saldo(): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $this->cabang->id)->where('bahan_baku_id', $this->mie->id)->value('saldo');
    }

    private function csv(string $isi): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('penjualan.csv', $isi);
    }

    private function pratinjau(string $isi): TestResponse
    {
        return $this->actingAs($this->adminCabang)->post(route('cabang.penjualan.impor.pratinjau'), ['file' => $this->csv($isi)])->assertOk();
    }

    private function simpan(TestResponse $pratinjau, array $tebakan = []): TestResponse
    {
        return $this->actingAs($this->adminCabang)->post(route('cabang.penjualan.impor.simpan'), ['kunci' => $pratinjau->viewData('kunci'), 'tebakan' => $tebakan]);
    }

    private function statusBaris(TestResponse $pratinjau): array
    {
        return $pratinjau->viewData('baris')->pluck('status', 'baris')->sortKeys()->all();
    }

    public function test_tombol_popup_dan_templat_berisi_semua_menu(): void
    {
        $this->actingAs($this->adminCabang)->get(route('cabang.penjualan'))
            ->assertOk()->assertSee(route('cabang.penjualan.impor'), false)->assertSee('Impor Excel');

        $this->get(route('cabang.penjualan.impor'))->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee(route('cabang.penjualan.impor.templat'), false)
            ->assertSee('Tanpa kolom Tanggal, penjualan dicatat untuk hari ini (sebelum jam 06:00 masih dianggap kemarin).');

        $berkas = tempnam(sys_get_temp_dir(), 'pj').'.xlsx';
        file_put_contents($berkas, $this->get(route('cabang.penjualan.impor.templat'))->assertOk()->streamedContent());
        $isi = IOFactory::load($berkas)->getSheet(0)->toArray(null, true, true);

        $this->assertSame(['Tanggal', 'Kategori Menu', 'Menu', 'Porsi'], $isi[4], 'kolom sama dengan Unduh Excel penjualan');
        $this->assertSame([now()->format('d/m/Y'), 'Ramen', 'Miso Ramen', '25'], $isi[5], 'porsi contoh data biasa');
        $this->assertSame([now()->format('d/m/Y'), 'Ramen', 'Shoyu Ramen', '18'], $isi[6]);

        $this->actingAs($this->adminUtama)->get(route('cabang.penjualan.impor'))->assertForbidden();
    }

    public function test_file_unduh_excel_penjualan_satu_hari_bisa_diunggah_balik(): void
    {
        $this->actingAs($this->adminCabang);
        $berkas = tempnam(sys_get_temp_dir(), 'pj').'.xlsx';
        file_put_contents($berkas, $this->get(route('cabang.penjualan.impor.templat'))->streamedContent());

        $buku = IOFactory::load($berkas);
        $buku->getSheet(0)->setCellValue('D6', 4);
        $buku->getSheet(0)->setCellValue('D7', 9);
        IOFactory::createWriter($buku, 'Xlsx')->save($berkas);

        $pratinjau = $this->post(route('cabang.penjualan.impor.pratinjau'), ['file' => new UploadedFile($berkas, 'pj.xlsx', null, null, true)])->assertOk();
        $this->assertSame([6 => 'siap', 7 => 'siap'], $this->statusBaris($pratinjau));
        $this->simpan($pratinjau)->assertSessionHas('sukses');

        $tanggal = now()->toDateString();
        $unduh = tempnam(sys_get_temp_dir(), 'pj').'.xlsx';
        file_put_contents($unduh, $this->get(route('cabang.penjualan.unduh', ['dari' => $tanggal, 'sampai' => $tanggal]))->assertOk()->streamedContent());
        $buku = IOFactory::load($unduh);
        $lembar = $buku->getSheet(0);
        $this->assertSame('Total', $lembar->getCell('A8')->getValue());
        $lembar->setCellValue('D7', 11);
        IOFactory::createWriter($buku, 'Xlsx')->save($unduh);

        $pratinjau = $this->post(route('cabang.penjualan.impor.pratinjau'), ['file' => new UploadedFile($unduh, 'unduh.xlsx', null, null, true)])->assertOk();
        $this->assertSame([6 => 'siap', 7 => 'siap'], $this->statusBaris($pratinjau), 'baris Total dilewati');
        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(11, (int) PenjualanHarian::sole()->detail()->where('menu_id', $this->shoyu->id)->value('porsi'));
    }

    public function test_menu_yang_sudah_tidak_dijual(): void
    {
        $this->miso->update(['aktif' => false]);

        $berkas = tempnam(sys_get_temp_dir(), 'pj').'.xlsx';
        file_put_contents($berkas, $this->actingAs($this->adminCabang)->get(route('cabang.penjualan.impor.templat'))->streamedContent());
        $isi = IOFactory::load($berkas)->getSheet(0)->toArray();
        $this->assertSame(['Shoyu Ramen'], array_values(array_filter(array_column(array_slice($isi, 5), 2))), 'templat hanya berisi menu yang masih dijual');

        $pratinjau = $this->pratinjau("Menu,Porsi\nShoyu Ramen,4\nMiso Ramen,0\nMiso Ramen Lama,\nRamen Musim Panas,0\nShoyu Ramn,\n");
        $this->assertSame([2 => 'siap', 3 => 'dilewati', 4 => 'dilewati', 5 => 'dilewati', 6 => 'dilewati'], $this->statusBaris($pratinjau));
        $this->assertSame([], $pratinjau->viewData('tebakan'), 'salah ketik di baris porsi kosong tidak ditanyakan');
        $pratinjau->assertSee('Porsi 0, menu sudah tidak dijual.')->assertSee('Porsi 0, menu tidak ada di daftar menu.');
        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame([$this->shoyu->id => 4], PenjualanHarian::sole()->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->all());

        $this->pratinjau("Tanggal,Menu,Porsi\n".now()->subDay()->format('d/m/Y').",Shoyu Ramen,4\n".now()->subDay()->format('d/m/Y').",Miso Ramen,2\n")
            ->assertSee('Menu Miso Ramen sudah tidak dijual (nonaktif). Kosongkan porsinya, atau minta Admin Utama mengaktifkan menu itu lagi.');
    }

    public function test_ubah_lewat_excel_dari_halaman_catatan(): void
    {
        $catatan = app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->subDay()->toDateString(), [$this->shoyu->id => 10, $this->miso->id => 5]);

        $this->actingAs($this->adminCabang)->get(route('cabang.penjualan.lihat', $catatan))
            ->assertSee(route('cabang.penjualan.impor', ['penjualan' => $catatan->id]), false)->assertSee('Ubah Lewat Excel');

        $this->get(route('cabang.penjualan.impor', ['penjualan' => $catatan->id]))->assertOk()
            ->assertSee('Unduh File Penjualan')
            ->assertSee(route('cabang.penjualan.impor.templat', ['penjualan' => $catatan->id]), false);

        $berkas = tempnam(sys_get_temp_dir(), 'pj').'.xlsx';
        file_put_contents($berkas, $this->get(route('cabang.penjualan.impor.templat', ['penjualan' => $catatan->id]))->streamedContent());
        $buku = IOFactory::load($berkas);
        $isi = $buku->getSheet(0)->toArray(null, true, true);
        $this->assertSame([now()->subDay()->format('d/m/Y'), 'Ramen', 'Miso Ramen', '5'], $isi[5]);
        $this->assertSame([now()->subDay()->format('d/m/Y'), 'Ramen', 'Shoyu Ramen', '10'], $isi[6]);

        $buku->getSheet(0)->setCellValue('D7', 12);
        IOFactory::createWriter($buku, 'Xlsx')->save($berkas);
        $pratinjau = $this->post(route('cabang.penjualan.impor.pratinjau'), ['file' => new UploadedFile($berkas, 'ubah.xlsx', null, null, true)])
            ->assertOk()->assertSee('Mengubah catatan');
        $this->simpan($pratinjau)->assertSessionHas('sukses');

        $this->assertSame([$this->shoyu->id => 12, $this->miso->id => 5], $catatan->fresh()->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->sortKeys()->all());
    }

    public function test_impor_hari_ini_tercatat_atas_nama_cabang_dan_stok_berkurang(): void
    {
        $pratinjau = $this->pratinjau("Kategori,Menu,Porsi\nRamen,shoyu ramen,12\nRamen,Miso Ramen,\n");
        $this->assertSame([2 => 'siap', 3 => 'dilewati'], $this->statusBaris($pratinjau));
        $this->assertNotEmpty($pratinjau->viewData('perkiraan'));

        $this->simpan($pratinjau)->assertRedirect(route('cabang.penjualan'))->assertSessionHas('sukses');

        $penjualan = PenjualanHarian::sole();
        $this->assertTrue($penjualan->dicatatCabang());
        $this->assertSame(now()->toDateString(), $penjualan->tanggal->toDateString());
        $this->assertSame([$this->shoyu->id => 12], $penjualan->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(3800.0, $this->saldo());
        $this->assertTrue(StokMutasi::where('cabang_id', $this->cabang->id)->where('jumlah', -1200)->exists());
        $this->assertTrue(LogPerubahan::where('aksi', 'impor.penjualan')->exists());
    }

    public function test_tanggal_sudah_dicatat_ditawarkan_ubah_dan_digabung(): void
    {
        app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->toDateString(), [$this->shoyu->id => 10, $this->miso->id => 5]);

        $pratinjau = $this->pratinjau("Menu,Qty\nShoyu Ramen,8\n");
        $this->assertSame([2 => 'siap'], $this->statusBaris($pratinjau));
        $pratinjau->assertSee('Mengubah catatan')->assertSee('Sebelumnya 10 porsi.');

        $this->simpan($pratinjau)->assertSessionHas('sukses');

        $this->assertSame(1, PenjualanHarian::count());
        $this->assertSame([$this->shoyu->id => 8, $this->miso->id => 5], PenjualanHarian::sole()->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->sortKeys()->all());
        $this->assertSame(5000.0 - 1300, $this->saldo());
        $this->assertTrue(LogPerubahan::where('aksi', 'penjualan.ubah')->exists());
    }

    public function test_aturan_tanggal_form_tetap_berlaku(): void
    {
        $lama = now()->subDays(8)->format('d/m/Y');
        $this->pratinjau("Tanggal,Menu,Porsi\n{$lama},Shoyu Ramen,3\n")
            ->assertSee('Penjualan hanya bisa dicatat paling lama 7 hari ke belakang.');

        $besok = now()->addDay()->format('d/m/Y');
        $this->pratinjau("Tanggal,Menu,Porsi\n{$besok},Shoyu Ramen,3\n")->assertSee('Tanggal penjualan tidak boleh setelah hari ini.');

        $kemarin = now()->subDay()->format('d-m-y');
        $pratinjau = $this->pratinjau("Tanggal,Menu,Porsi\n{$kemarin},Shoyu Ramen,3\n".now()->format('d/m/Y').",Miso Ramen,2\n");
        $this->assertSame([2 => 'salah', 3 => 'salah'], $this->statusBaris($pratinjau));
        $pratinjau->assertSee('Satu file untuk satu tanggal.');

        $pratinjau = $this->pratinjau("Tanggal,Menu,Porsi\n{$kemarin},Shoyu Ramen,3\n");
        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(now()->subDay()->toDateString(), PenjualanHarian::sole()->tanggal->toDateString());
    }

    public function test_catatan_impor_admin_utama_tidak_bisa_diganti_cabang_dan_sebaliknya(): void
    {
        app(PenjualanService::class)->terapkanImpor($this->cabang, now()->subDay()->toDateString(), [$this->shoyu->id => 4], $this->adminUtama);

        $this->pratinjau("Tanggal,Menu,Porsi\n".now()->subDay()->format('d/m/Y').",Shoyu Ramen,9\n")
            ->assertSee('Penjualan tanggal ini diimpor Admin Utama, jadi tidak bisa diubah dari cabang.');

        $this->simpan($this->pratinjau("Menu,Porsi\nShoyu Ramen,6\n"))->assertSessionHas('sukses');

        $file = UploadedFile::fake()->createWithContent('pj.csv', "tanggal,lokasi,menu,porsi\n".now()->format('d/m/Y').",JTI,Shoyu Ramen,99\n");
        $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.pratinjau', 'penjualan'), ['file' => $file])
            ->assertOk()->assertSee('sudah dicatat cabang, jadi tidak diganti.');
    }

    public function test_salah_ketik_ditanyakan_dan_lokasi_lain_dilewati(): void
    {
        $pratinjau = $this->pratinjau("Outlet,Nama Menu,Terjual\nJTI,Shoyu Ramn,5\nKNG,Miso Ramen,7\nJati,Miso Ramen,2\n");
        $baris = $pratinjau->viewData('baris')->keyBy('baris');

        $this->assertSame('cek', $baris[2]['status']);
        $this->assertSame('dilewati', $baris[3]['status']);
        $this->assertSame('cek', $baris[4]['status'], 'satu file satu catatan: ikut menunggu jawaban');

        $kunci = collect($pratinjau->viewData('tebakan'))->firstWhere('asli', 'Shoyu Ramn')['kunci'];
        $this->simpan($pratinjau, [$kunci => 'terima', collect($pratinjau->viewData('tebakan'))->firstWhere('asli', 'Jati')['kunci'] ?? 'x' => 'terima'])
            ->assertSessionHas('sukses');

        $this->assertSame([$this->shoyu->id => 5, $this->miso->id => 2], PenjualanHarian::sole()->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->sortKeys()->all());
    }

    public function test_pilihan_ganti_dan_tambah_saat_impor_penjualan_hari_yang_sudah_dicatat(): void
    {
        $this->actingAs($this->adminCabang)->get(route('cabang.penjualan.impor'))
            ->assertSee('name="mode" value="ganti" checked', false)
            ->assertSee('name="mode" value="tambah"', false);

        app(PenjualanService::class)->catat($this->adminCabang, $this->cabang, now()->toDateString(), [$this->shoyu->id => 10, $this->miso->id => 4]);
        $this->assertSame(3600.0, $this->saldo());

        $tambah = $this->actingAs($this->adminCabang)->post(route('cabang.penjualan.impor.pratinjau'), [
            'mode' => 'tambah', 'file' => $this->csv("Menu,Porsi\nShoyu Ramen,5\n"),
        ])->assertOk()->assertSee('Sebelumnya 10 porsi, menjadi 15 porsi.')->assertSee('ditambahkan ke porsi yang sudah ada');
        $this->simpan($tambah)->assertSessionHas('sukses');

        $porsi = fn () => PenjualanHarian::sole()->detail()->pluck('porsi', 'menu_id')->map(fn ($p) => (int) $p)->all();
        $this->assertSame([$this->shoyu->id => 15, $this->miso->id => 4], $porsi());
        $this->assertSame(3100.0, $this->saldo(), 'bahan 5 porsi tambahan ikut terpotong');

        $ganti = $this->actingAs($this->adminCabang)->post(route('cabang.penjualan.impor.pratinjau'), [
            'mode' => 'ganti', 'file' => $this->csv("Menu,Porsi\nShoyu Ramen,5\n"),
        ])->assertOk()->assertSee('Sebelumnya 15 porsi.');
        $this->simpan($ganti)->assertSessionHas('sukses');

        $this->assertSame([$this->shoyu->id => 5, $this->miso->id => 4], $porsi());
        $this->assertSame(4100.0, $this->saldo());
    }

    public function test_tabel_sendiri_dengan_kolom_lain_tetap_terbaca(): void
    {
        $pratinjau = $this->pratinjau("No,Nama,Terjual Hari Ini,Harga\n1,Shoyu Ramen,4,35000\n2,Miso Ramen,2,38000\n");
        $this->assertSame(['siap'], array_values(array_unique($this->statusBaris($pratinjau))));

        $tanpaJudul = $this->pratinjau("Shoyu Ramen,4\nMiso Ramen,2\n");
        $this->assertSame(['siap'], array_values(array_unique($this->statusBaris($tanpaJudul))));
    }
}
