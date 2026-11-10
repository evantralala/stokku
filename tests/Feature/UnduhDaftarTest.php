<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
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
use App\Support\BukuExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class UnduhDaftarTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $ayam;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('public');

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JB', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $this->ayam = BahanBaku::create([
            'nama' => 'Ayam fillet', 'kategori_id' => Kategori::create(['nama' => 'Protein'])->id,
            'satuan_dasar_id' => Satuan::create(['nama' => 'gram'])->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->ayam->id, 'saldo' => 20000, 'diperbarui_pada' => now()]);
    }

    private function lembar(TestResponse $respons): Worksheet
    {
        $respons->assertOk()->assertHeader('content-type', BukuExcel::MIME);

        $berkas = tempnam(sys_get_temp_dir(), 'unduh').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return IOFactory::load($berkas)->getActiveSheet();
    }

    /** Baris data pertama (setelah judul, keterangan, dan kepala tabel). */
    private function barisPertama(Worksheet $lembar): array
    {
        return $lembar->rangeToArray('A6:'.$lembar->getHighestColumn().'6', null, true, false)[0];
    }

    private function kirimEmpatKg(): Kiriman
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris' => [['bahan_baku_id' => $this->ayam->id, 'qty' => '4']],
        ])->assertSessionHasNoErrors();
        $permintaan = Permintaan::latest('id')->first();
        $this->app->forgetScopedInstances();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
            'keputusan' => [$permintaan->detail()->first()->id => ['aksi' => 'setujui', 'qty' => '4']],
        ]);
        $this->actingAs($this->adminUtama)->post(route('admin-utama.kiriman.simpan', $permintaan), ['jumlah' => [$permintaan->detail()->first()->id => '4']]);
        $kiriman = Kiriman::firstOrFail();

        $this->app->forgetScopedInstances();
        $this->actingAs($this->adminCabang)->post(route('cabang.terima-barang.simpan', $kiriman), [
            'kesesuaian' => 'tidak_sesuai',
            'terima'     => [$kiriman->detail()->first()->id => ['qty' => '3,5', 'kondisi' => 'baik', 'catatan' => 'Kurang setengah']],
        ])->assertSessionHasNoErrors();

        return $kiriman;
    }

    public function test_stok_saat_ini_diunduh_dalam_satuan_beli(): void
    {
        $lembar = $this->lembar($this->actingAs($this->adminPusat)->get(route('pusat.stok.unduh')));

        $this->assertSame('Stok Gudang Pusat', $lembar->getCell('A1')->getValue());
        $this->assertEquals(['Protein', 'Ayam fillet', 'kg', 20], array_slice($this->barisPertama($lembar), 0, 4));

        $this->actingAs($this->adminPusat)->get(route('pusat.stok'))
            ->assertSee('href="'.route('pusat.stok.unduh').'"', false);

        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok.unduh', ['lokasi' => $this->cabang->id]))
            ->assertOk()
            ->assertDownload('stok_cabang-jati_'.now()->toDateString().'.xlsx');
    }

    public function test_riwayat_kiriman_permintaan_dan_terima_barang_satu_baris_per_bahan(): void
    {
        $kiriman = $this->kirimEmpatKg();
        $hari = ['dari' => now()->toDateString(), 'sampai' => now()->toDateString()];

        $baris = $this->barisPertama($this->lembar($this->actingAs($this->adminUtama)->get(route('admin-utama.kiriman.unduh', $hari))));
        $this->assertSame([$kiriman->nomor, 'Cabang Jati'], array_slice($baris, 1, 2));
        $this->assertEquals([4, 3.5, -0.5], array_slice($baris, 6, 3));

        $baris = $this->barisPertama($this->lembar($this->actingAs($this->adminCabang)->get(route('cabang.terima-barang.unduh', $hari))));
        $this->assertSame('Gudang Pusat', $baris[2]);
        $this->assertSame('Kurang setengah', $baris[10]);

        $baris = $this->barisPertama($this->lembar($this->actingAs($this->adminCabang)->get(route('cabang.permintaan.unduh', $hari))));
        $this->assertSame('Ayam fillet', $baris[2]);
        $this->assertEquals([4, 4], array_slice($baris, 4, 2));

        $lembar = $this->lembar($this->actingAs($this->adminCabang)->get(route('cabang.stok.mutasi.unduh', $hari)));
        $this->assertSame('Riwayat stok Cabang Jati', $lembar->getCell('A1')->getValue());
        $this->assertSame('Ayam fillet', $lembar->getCell('B6')->getValue());
        $this->assertEquals(3.5, $lembar->getCell('F6')->getValue());
    }

    public function test_tanpa_periode_tampil_pilihan_periode_sebagai_popup(): void
    {
        $this->actingAs($this->adminCabang)->get(route('cabang.permintaan'))
            ->assertSee('href="'.route('cabang.permintaan.unduh').'"  data-popup data-popup-judul="Unduh Excel"', false);

        $this->actingAs($this->adminCabang)->get(route('cabang.permintaan.unduh'))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('name="dari"', false)
            ->assertSee('Bulan Lalu');
    }

    public function test_barang_rusak_dan_penjualan_bisa_diunduh(): void
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.barang-keluar.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->ayam->id, 'qty' => '1,5', 'alasan' => 'Bau asam']],
        ])->assertSessionHasNoErrors();

        $hari = ['dari' => now()->toDateString(), 'sampai' => now()->toDateString()];
        $baris = $this->barisPertama($this->lembar($this->actingAs($this->adminPusat)->get(route('pusat.barang-keluar.unduh', $hari))));
        $this->assertEquals(['Ayam fillet', 'kg', 1.5], array_slice($baris, 2, 3));
        $this->assertSame('Bau asam', $baris[5]);

        $menu = Menu::create(['nama' => 'Chicken katsu', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Nasi'])->id]);
        Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $this->ayam->id, 'jumlah' => 100, 'berlaku_sejak' => now()->subDay()->toDateString()]);
        $this->actingAs($this->adminCabang)->post(route('cabang.penjualan.simpan'), ['tanggal' => now()->toDateString(), 'porsi' => [$menu->id => 12]])
            ->assertSessionHasNoErrors();

        $lembar = $this->lembar($this->actingAs($this->adminCabang)->get(route('cabang.penjualan.unduh', $hari)));
        $this->assertEquals(['Nasi', 'Chicken katsu', 12], array_slice($this->barisPertama($lembar), 1, 3));
        $this->assertSame('Total', $lembar->getCell('A7')->getValue());

        $this->actingAs($this->adminCabang)->get(route('pusat.stok.unduh'))->assertForbidden();
    }

    public function test_unduhan_memakai_tabel_excel_dengan_format_standar(): void
    {
        $lembar = $this->lembar($this->actingAs($this->adminPusat)->get(route('pusat.stok.unduh')));

        $this->assertSame(['Tabel1'], $lembar->getTableNames());
        $this->assertSame('A5:J6', $lembar->getTableByName('Tabel1')->getRange());
        $this->assertSame('TableStyleMedium1', $lembar->getTableByName('Tabel1')->getStyle()->getTheme());
        $this->assertSame('', $lembar->getAutoFilter()->getRange());
        $this->assertSame('#,##0', $lembar->getStyle('D6')->getNumberFormat()->getFormatCode());
        $this->assertSame([5, 5], $lembar->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertSame('A6', $lembar->getFreezePane());

        $kosong = $this->lembar($this->actingAs($this->adminCabang)->get(route('cabang.barang-keluar.unduh', [
            'dari' => now()->toDateString(), 'sampai' => now()->toDateString(),
        ])));
        $this->assertSame(1, count($kosong->getTableNames()));
        $this->assertTrue($kosong->getStyle('A6')->getFont()->getItalic());
    }
}
