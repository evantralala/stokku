<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\PenjualanDetail;
use App\Models\PenjualanHarian;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Laporan per kategori (gaya Majoo) dan riwayat perubahan di setiap fitur. */
class LaporanKategoriRiwayatTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $cabang;
    private Kategori $kering;
    private Kategori $sayur;
    private Satuan $gram;
    private Satuan $kg;
    private BahanBaku $nori;
    private BahanBaku $kol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->gram = Satuan::create(['nama' => 'gram']);
        $this->kg = Satuan::create(['nama' => 'kg']);
        $this->kering = Kategori::create(['nama' => 'Kering', 'urutan' => 1]);
        $this->sayur = Kategori::create(['nama' => 'Sayur', 'urutan' => 2]);

        $buat = fn (string $nama, Kategori $k) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $k->id, 'satuan_dasar_id' => $this->gram->id, 'satuan_beli_id' => $this->kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = $buat('Nori', $this->kering);
        $this->kol = $buat('Kol', $this->sayur);

        foreach ([$this->nori, $this->kol] as $b) {
            DB::table('stok_mutasi')->insert([
                'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'jumlah' => 5000, 'jenis' => StokMutasi::JENIS_STOK_AWAL,
                'status' => StokMutasi::STATUS_SAH, 'dokumen_tipe' => 'impor_stok', 'dokumen_id' => 1, 'dibuat_pada' => '2026-10-05 08:00:00',
            ]);
        }

        $ramen = KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);
        $minuman = KategoriMenu::create(['nama' => 'Minuman', 'urutan' => 2]);
        $porsi = [
            [Menu::create(['nama' => 'Shoyu', 'kategori_menu_id' => $ramen->id]), 35],
            [Menu::create(['nama' => 'Miso', 'kategori_menu_id' => $ramen->id]), 10],
            [Menu::create(['nama' => 'Ocha', 'kategori_menu_id' => $minuman->id]), 40],
        ];
        $bk = BarangKeluar::create([
            'nomor' => 'BK-1', 'cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-10', 'jenis' => BarangKeluar::JENIS_PEMAKAIAN,
            'dicatat_oleh' => $this->adminUtama->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
        ]);
        $penjualan = PenjualanHarian::create(['cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-10', 'barang_keluar_id' => $bk->id, 'dicatat_oleh' => $this->adminUtama->id]);
        foreach ($porsi as [$menu, $jumlah]) {
            PenjualanDetail::create(['penjualan_harian_id' => $penjualan->id, 'menu_id' => $menu->id, 'porsi' => $jumlah, 'takaran_resep_json' => []]);
        }
    }

    public function test_rekap_stok_dikelompokkan_dan_bisa_disaring_per_kategori(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.stok', ['lokasi' => $this->cabang->id]))
            ->assertOk()
            ->assertSeeInOrder(['baris-kategori', 'Kering', 'Nori', 'baris-kategori', 'Sayur', 'Kol'], false);

        $this->get(route('admin-utama.laporan.stok', ['lokasi' => $this->cabang->id, 'kategori' => $this->sayur->id]))
            ->assertOk()
            ->assertSee('Kol')
            ->assertDontSee('>Nori<', false);
    }

    public function test_laporan_per_kategori_menu_dengan_persen_dan_excel(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.laporan'))->assertSee(route('admin-utama.laporan.kategori'), false);

        $this->get(route('admin-utama.laporan.kategori', ['dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertOk()
            ->assertSeeInOrder(['Ramen', '45', '52,9%', 'Shoyu', 'Minuman', '40', '47,1%', 'Ocha'], false);

        $this->get(route('admin-utama.laporan.penjualan', ['dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertSeeInOrder(['Ramen', '45 porsi', 'Shoyu', 'Miso', 'Minuman', 'Ocha']);

        $berkas = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        file_put_contents($berkas, $this->get(route('admin-utama.laporan.unduh', ['jenis' => 'kategori', 'dari' => '2026-10-01', 'sampai' => '2026-10-20']))->streamedContent());
        $buku = IOFactory::load($berkas);

        $this->assertSame(['Penjualan per Kategori', 'Bahan per Kategori'], $buku->getSheetNames());
        $this->assertSame('Ramen', $buku->getSheet(0)->getCell('A6')->getValue());
        $this->assertEquals(45, $buku->getSheet(0)->getCell('B6')->getValue());
        $this->assertEquals(0.529, $buku->getSheet(0)->getCell('C6')->getValue());
    }

    public function test_laporan_penjualan_tanpa_kolom_web_order(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.penjualan', ['dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertOk()
            ->assertDontSee('Web Order');
    }

    public function test_perubahan_data_master_tercatat_dengan_nilai_lama_dan_baru(): void
    {
        $this->actingAs($this->adminUtama)->post(route('admin-utama.bahan-baku.simpan'), [
            'nama' => 'Daun bawang', 'kategori_id' => $this->sayur->id, 'satuan_dasar_id' => $this->gram->id,
            'satuan_beli_id' => $this->kg->id, 'isi_per_satuan_beli' => '1000', 'toleransi_persen' => '5',
        ])->assertSessionHasNoErrors();

        $bahan = BahanBaku::where('nama', 'Daun bawang')->sole();
        $tambah = LogPerubahan::where('aksi', 'data.tambah')->sole();
        $this->assertSame(LogPerubahan::KATEGORI_DATA, $tambah->kategori);
        $this->assertSame('Ditambah: Bahan baku Daun bawang', $tambah->ringkasan);
        $this->assertSame($this->adminUtama->id, $tambah->user_id);

        $this->put(route('admin-utama.bahan-baku.perbarui', $bahan), [
            'nama' => 'Daun bawang', 'kategori_id' => $this->kering->id, 'satuan_dasar_id' => $this->gram->id,
            'satuan_beli_id' => $this->kg->id, 'isi_per_satuan_beli' => '1000', 'toleransi_persen' => '5',
        ])->assertSessionHasNoErrors();

        $ubah = LogPerubahan::where('aksi', 'data.ubah')->sole();
        $this->assertSame([['label' => 'Kategori', 'lama' => 'Sayur', 'baru' => 'Kering']], $ubah->rincian);

        $this->patch(route('admin-utama.bahan-baku.aktif', $bahan), ['aktif' => '0']);
        $this->assertSame('Dinonaktifkan: Bahan baku Daun bawang', LogPerubahan::latest('id')->first()->ringkasan);

        $this->get(route('admin-utama.bahan-baku'))->assertSee(route('admin-utama.riwayat', ['jenis' => 'bahan-baku']), false);
        $this->get(route('admin-utama.riwayat', ['jenis' => 'bahan-baku']))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('Ditambah: Bahan baku Daun bawang')
            ->assertSee('Dinonaktifkan: Bahan baku Daun bawang')
            ->assertSee('Sayur')
            ->assertDontSee('Ditambah: Bahan baku Nori');
    }

    public function test_impor_excel_dicatat_sebagai_satu_ringkasan(): void
    {
        $this->actingAs($this->adminUtama);
        $file = UploadedFile::fake()->createWithContent('satuan.csv', "nama\npack\nbotol\nikat\n");
        $pratinjau = $this->post(route('admin-utama.excel.pratinjau', 'satuan'), ['file' => $file])->assertOk();
        $this->post(route('admin-utama.excel.simpan', 'satuan'), ['kunci' => $pratinjau->viewData('kunci')])->assertSessionHas('sukses');

        $this->assertSame(0, LogPerubahan::where('aksi', 'data.tambah')->count());
        $this->assertSame(1, LogPerubahan::where('aksi', 'impor.satuan')->count());

        $this->get(route('admin-utama.riwayat', ['jenis' => 'satuan']))->assertSee('Impor 3 satuan');
    }

    public function test_riwayat_stok_minimum_resep_dan_akses(): void
    {
        $this->actingAs($this->adminUtama)->post(route('admin-utama.stok-minimum.simpan'), [
            'lokasi_id' => $this->cabang->id, 'minimum' => [$this->nori->id => '2'],
        ])->assertSessionHas('sukses');

        $menu = Menu::where('nama', 'Shoyu')->sole();
        $this->post(route('admin-utama.menu.resep.simpan', $menu), [
            'berlaku_sejak' => '2026-10-20',
            'baris' => [['bahan_baku_id' => $this->nori->id, 'jumlah' => '5']],
        ])->assertSessionHasNoErrors();

        $this->get(route('admin-utama.riwayat', ['jenis' => 'stok-minimum']))->assertSee('Stok minimum Cabang Jati disimpan: 1 bahan');
        $this->get(route('admin-utama.menu.resep', $menu))->assertSee(route('admin-utama.riwayat', ['jenis' => 'menu', 'objek' => $menu->id]));
        $this->get(route('admin-utama.riwayat', ['jenis' => 'menu', 'objek' => $menu->id]))
            ->assertSee('Riwayat Menu &amp; Resep: Shoyu', false)
            ->assertSee('Resep Shoyu berlaku 20 Okt 2026: 1 bahan');

        $this->get(route('admin-utama.riwayat', ['jenis' => 'gaji']))->assertNotFound();

        $admin = User::factory()->adminCabang($this->cabang->id)->create();
        $this->actingAs($admin)->get(route('admin-utama.riwayat', ['jenis' => 'menu']))->assertForbidden();
    }
}
