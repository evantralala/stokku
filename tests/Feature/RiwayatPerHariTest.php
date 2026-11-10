<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Support\BukuExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Tab "Per Hari" di Riwayat stok: stok awal, masuk, keluar, dan stok akhir hari ini dan hari sebelumnya. */
class RiwayatPerHariTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private User $adminCabang;
    private BahanBaku $beras;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-23 10:00'));

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

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

        foreach ([
            [$this->beras, 10000, StokMutasi::JENIS_STOK_AWAL, '2026-10-21 08:00'],
            [$this->beras, -2000, StokMutasi::JENIS_PAKAI_DAPUR, '2026-10-22 20:00'],
            [$this->nori, 500, StokMutasi::JENIS_TAMBAH_LANGSUNG, '2026-10-22 09:00'],
            [$this->beras, 5000, StokMutasi::JENIS_MASUK_SUPPLIER, '2026-10-23 07:00'],
            [$this->beras, -500, StokMutasi::JENIS_RUSAK, '2026-10-23 09:00'],
        ] as $i => [$bahan, $jumlah, $jenis, $waktu]) {
            DB::table('stok_mutasi')->insert([
                'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $bahan->id, 'jumlah' => $jumlah, 'jenis' => $jenis,
                'status' => StokMutasi::STATUS_SAH, 'dokumen_tipe' => 'uji', 'dokumen_id' => $i + 1, 'dibuat_pada' => $waktu,
            ]);
        }
        DB::table('stok_mutasi')->insert([
            'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => 900, 'jenis' => StokMutasi::JENIS_MASUK_SUPPLIER,
            'status' => StokMutasi::STATUS_MENUNGGU, 'dokumen_tipe' => 'uji', 'dokumen_id' => 9, 'dibuat_pada' => '2026-10-23 08:00',
        ]);
    }

    public function test_riwayat_dibuka_di_tab_per_hari_hari_ini(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.mutasi'))
            ->assertOk()
            ->assertSee('Per Hari')
            ->assertSee('Hari Ini')
            ->assertSee('1 bahan berubah')
            ->assertSeeInOrder(['Beras Jepang', 'Barang Masuk +5', 'Rusak / Terbuang -0,5', '8 kg', '+5 kg', '-0,5 kg', '12,5 kg'])
            ->assertSee('aria-label="Hari sebelumnya, 22 Okt 2026"', false)
            ->assertSee('aria-disabled="true"', false)
            ->assertDontSee('Nori');
    }

    public function test_hari_sebelumnya_menampilkan_stok_kemarin(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.mutasi', ['tampil' => 'hari', 'tanggal' => '2026-10-22']))
            ->assertOk()
            ->assertSee('Kemarin')
            ->assertSee('2 bahan berubah')
            ->assertSeeInOrder(['Bahan pokok', 'Beras Jepang', '10 kg', '-2 kg', '8 kg', 'Kering', 'Nori', '0 gram', '+500 gram', '500 gram'])
            ->assertSee('aria-label="Hari berikutnya, 23 Okt 2026"', false);

        $this->get(route('cabang.stok.mutasi', ['tampil' => 'hari', 'tanggal' => '2026-10-20']))
            ->assertOk()
            ->assertSee('Tidak ada perubahan stok pada 20 Okt 2026.');
    }

    public function test_semua_bahan_menampilkan_bahan_yang_tidak_berubah(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.mutasi', ['tampil' => 'hari', 'semua' => 1]))
            ->assertOk()
            ->assertSee('1 bahan berubah')
            ->assertSeeInOrder(['Nori', '500 gram', '—', '—', '500 gram']);

        $this->get(route('cabang.stok.mutasi', ['tampil' => 'hari', 'cari' => 'nori']))
            ->assertSee('0 bahan berubah')
            ->assertDontSee('Beras Jepang');
    }

    public function test_tanggal_setelah_hari_ini_kembali_ke_hari_ini(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.mutasi', ['tampil' => 'hari', 'tanggal' => '2026-12-01']))
            ->assertOk()
            ->assertSee('Hari Ini')
            ->assertSee('12,5 kg');

        $this->get(route('cabang.stok.mutasi', ['tampil' => 'hari', 'tanggal' => 'bukan-tanggal']))->assertOk()->assertSee('Hari Ini');
    }

    public function test_alamat_lama_dengan_periode_tetap_membuka_masuk_dan_keluar(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.mutasi', ['dari' => '2026-10-21', 'sampai' => '2026-10-23']))
            ->assertOk()
            ->assertSee('Pemakaian Dapur')
            ->assertDontSee('bahan berubah');
    }

    public function test_excel_stok_per_hari(): void
    {
        $respons = $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.mutasi.unduh', ['tampil' => 'hari', 'tanggal' => '2026-10-22']))
            ->assertOk()
            ->assertHeader('content-type', BukuExcel::MIME);

        $berkas = tempnam(sys_get_temp_dir(), 'hari').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());
        $lembar = IOFactory::load($berkas)->getActiveSheet();

        $this->assertSame('Stok per Hari Cabang Jati', $lembar->getCell('A1')->getValue());
        $baris = $lembar->rangeToArray('A6:H6', null, true, false)[0];
        $this->assertSame(['Bahan pokok', 'Beras Jepang', 'kg'], array_slice($baris, 0, 3));
        $this->assertEquals([10, null, 2, 8], array_slice($baris, 3, 4));
        $this->assertSame('Pemakaian Dapur -2', $baris[7]);
    }
}
