<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\PenjualanDetail;
use App\Models\PenjualanHarian;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class EksporLaporanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 20, 12));

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
    }

    private function bukaExcel(TestResponse $respons): Spreadsheet
    {
        $berkas = tempnam(sys_get_temp_dir(), 'laporan').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return IOFactory::load($berkas);
    }

    private function mutasi(string $jenis, float $jumlah, string $waktu): void
    {
        static $urut = 0;

        DB::table('stok_mutasi')->insert([
            'cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => $jumlah, 'jenis' => $jenis,
            'status' => StokMutasi::STATUS_SAH, 'dokumen_tipe' => 'uji', 'dokumen_id' => ++$urut, 'dibuat_pada' => $waktu,
        ]);
    }

    public function test_rekap_stok_terunduh_dengan_angka_satuan_beli_yang_sama_dengan_halaman(): void
    {
        $this->mutasi(StokMutasi::JENIS_MASUK_SUPPLIER, 5000, '2026-09-25 10:00:00');
        $this->mutasi(StokMutasi::JENIS_MASUK_SUPPLIER, 3000, '2026-10-05 10:00:00');
        $this->mutasi(StokMutasi::JENIS_KIRIM_KELUAR, -2000, '2026-10-06 10:00:00');
        $this->mutasi(StokMutasi::JENIS_KOREKSI_OPNAME, -100, '2026-10-10 10:00:00');

        $respons = $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.unduh', ['jenis' => 'stok', 'dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString(
            'laporan-rekap-stok_gudang-pusat_2026-10-01_2026-10-20.xlsx',
            $respons->headers->get('content-disposition')
        );

        $lembar = $this->bukaExcel($respons)->getSheetByName('Rekap Stok');

        $this->assertSame('Rekap Stok', $lembar->getCell('A1')->getValue());
        $this->assertStringContainsString('Gudang Pusat', $lembar->getCell('A2')->getValue());
        $this->assertSame(['Bahan', 'Kategori', 'Satuan', 'Awal'], array_slice($lembar->rangeToArray('A5:D5')[0], 0, 4));
        $this->assertSame('Akhir', $lembar->getCell('K5')->getValue());

        $this->assertSame('Nori', $lembar->getCell('A6')->getValue());
        $this->assertSame('kg', $lembar->getCell('C6')->getValue());
        $this->assertEquals(5, $lembar->getCell('D6')->getValue());
        $this->assertEquals(3, $lembar->getCell('E6')->getValue());
        $this->assertEquals(-2, $lembar->getCell('G6')->getValue());
        $this->assertEquals(-0.1, $lembar->getCell('J6')->getValue());
        $this->assertEquals(5.9, $lembar->getCell('K6')->getValue());
    }

    public function test_penjualan_terunduh_dua_lembar_dengan_total_porsi(): void
    {
        $menu = Menu::create(['nama' => 'Shoyu', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen'])->id]);
        $bk = BarangKeluar::create([
            'nomor' => 'BK-1', 'cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-10', 'jenis' => BarangKeluar::JENIS_PEMAKAIAN,
            'dicatat_oleh' => $this->adminUtama->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
        ]);
        $penjualan = PenjualanHarian::create(['cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-10', 'barang_keluar_id' => $bk->id, 'dicatat_oleh' => $this->adminUtama->id]);
        PenjualanDetail::create(['penjualan_harian_id' => $penjualan->id, 'menu_id' => $menu->id, 'porsi' => 40, 'takaran_resep_json' => []]);

        $buku = $this->bukaExcel(
            $this->actingAs($this->adminUtama)->get(route('admin-utama.laporan.unduh', ['jenis' => 'penjualan', 'dari' => '2026-10-01', 'sampai' => '2026-10-20']))
        );

        $this->assertSame(['Penjualan Menu', 'Pemakaian Bahan'], $buku->getSheetNames());

        $menuLembar = $buku->getSheetByName('Penjualan Menu');
        $this->assertSame('Ramen', $menuLembar->getCell('A6')->getValue());
        $this->assertSame('Shoyu', $menuLembar->getCell('B6')->getValue());
        $this->assertEquals(40, $menuLembar->getCell('C6')->getValue());
        $this->assertSame('Total', $menuLembar->getCell('A7')->getValue());
        $this->assertEquals(40, $menuLembar->getCell('C7')->getCalculatedValue());
    }

    public function test_lembar_kosong_tetap_terbuka_dan_laporan_supplier_tidak_ada_lagi(): void
    {
        $respons = $this->actingAs($this->adminUtama)->get(route('admin-utama.laporan.unduh', ['jenis' => 'rusak']));

        $this->assertStringContainsString('laporan-barang-rusak_semua-lokasi_2026-10-01_2026-10-20.xlsx', $respons->headers->get('content-disposition'));
        $this->assertSame('Tidak ada data pada periode ini.', $this->bukaExcel($respons)->getActiveSheet()->getCell('A6')->getValue());

        $this->get(route('admin-utama.laporan.unduh', ['jenis' => 'supplier']))->assertNotFound();
    }

    public function test_jenis_tidak_dikenal_dan_bukan_admin_utama(): void
    {
        $this->actingAs($this->adminUtama)->get('/admin-utama/laporan/gaji/unduh')->assertNotFound();

        $admin = User::factory()->adminCabang($this->cabang->id)->create();
        $this->actingAs($admin)->get(route('admin-utama.laporan.unduh', ['jenis' => 'stok']))->assertForbidden();
    }

    public function test_halaman_laporan_menampilkan_tombol_unduh_dengan_saringan_yang_sama(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.rusak', ['dari' => '2026-10-02', 'sampai' => '2026-10-09', 'lokasi' => $this->cabang->id]))
            ->assertSee('Unduh Excel')
            ->assertSee(route('admin-utama.laporan.unduh', ['jenis' => 'rusak', 'dari' => '2026-10-02', 'sampai' => '2026-10-09', 'lokasi' => $this->cabang->id]));
    }
}
