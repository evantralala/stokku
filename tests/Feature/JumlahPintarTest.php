<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use App\Services\StokService;
use App\Support\JumlahPintar;
use App\Support\SatuanTunggal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Jumlah ditulis bebas ("2x10", "10 Liter", "500 ml"), kolom Isi dikalikan, dan bahan dobel dijumlahkan. */
class JumlahPintarTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private User $utama;
    private BahanBaku $galon;
    private BahanBaku $beras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->utama = User::factory()->adminUtama()->create();
        $kategori = Kategori::create(['nama' => 'Minuman', 'urutan' => 1]);
        $bahan = fn (string $nama, string $satuan) => BahanBaku::create(['nama' => $nama, 'kategori_id' => $kategori->id,
            'toleransi_persen' => 5] + SatuanTunggal::untuk(Satuan::firstOrCreate(['nama' => $satuan])));
        $this->galon = $bahan('Air Galon', 'liter');
        $this->beras = $bahan('Beras', 'kg');
    }

    private function stok(BahanBaku $b): float
    {
        return $b->keSatuanBeli(app(StokService::class)->saldo($this->pusat->id, $b->id));
    }

    /** @param  list<list<mixed>>  $baris */
    private function file(array $baris, bool $tabel = false): UploadedFile
    {
        $buku = new Spreadsheet();
        $lembar = $buku->getActiveSheet()->fromArray($baris, null, 'A1', true);
        if ($tabel) {
            $lembar->addTable(new Table('A1:'.chr(64 + count($baris[0])).count($baris), 'Table1'));
        }
        $berkas = tempnam(sys_get_temp_dir(), 'jp').'.xlsx';
        (new Xlsx($buku))->save($berkas);

        return new UploadedFile($berkas, 'stok.xlsx', null, null, true);
    }

    private function tambahStok(UploadedFile $file): TestResponse
    {
        $pratinjau = $this->actingAs($this->utama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]),
            ['mode' => 'tambah', 'file' => $file])->assertOk();
        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertSessionHas('sukses');

        return $pratinjau;
    }

    public function test_membaca_jumlah_bebas_dan_mengonversi_ke_satuan_bahan(): void
    {
        $this->assertSame([38.0, null], JumlahPintar::pecah('2x19'));
        $this->assertSame([20.0, 'liter'], JumlahPintar::pecah('2 x 10 Liter'));
        $this->assertSame([1.5, 'kg'], JumlahPintar::pecah('1,5 kilo'));
        $this->assertNull(JumlahPintar::pecah('dua galon'));

        $this->assertSame(20.0, JumlahPintar::keSatuanBahan('2x10 liter', $this->galon)[0]);
        $this->assertSame(0.5, JumlahPintar::keSatuanBahan('500 ml', $this->galon)[0]);
        $this->assertSame(0.25, JumlahPintar::keSatuanBahan('250 gram', $this->beras)[0]);
        $this->assertSame(3.0, JumlahPintar::keSatuanBahan('3', $this->beras)[0]);
        $this->assertNull(JumlahPintar::keSatuanBahan('2 galon', $this->galon)[0]);
        $this->assertStringContainsString('tidak cocok', JumlahPintar::keSatuanBahan('2 galon', $this->galon)[1]);
    }

    public function test_tabel_tanpa_judul_jumlah_kali_isi_bersatuan(): void
    {
        $this->tambahStok($this->file([['Column1', 'Column2', 'Column3'], ['Air Galon', 2, '10 Liter']], true));

        $this->assertSame(20.0, $this->stok($this->galon));
    }

    public function test_bahan_dobel_dijumlahkan_dengan_keterangan(): void
    {
        $pratinjau = $this->tambahStok($this->file([['Column1', 'Column2'], ['Air Galon', '10 Liter'], ['Air Galon', '10 Liter'], ['Beras', '500 gram']], true));

        $pratinjau->assertSee('Digabung dari baris 2, 3')->assertSee('Digabung ke baris 2.');
        $this->assertSame(20.0, $this->stok($this->galon));
        $this->assertSame(0.5, $this->stok($this->beras));
    }

    public function test_judul_jumlah_dan_isi_dikalikan(): void
    {
        $this->tambahStok($this->file([['Bahan', 'Jumlah', 'Isi'], ['Air Galon', 3, '19 liter'], ['Beras', '2x5', null]]));

        $this->assertSame(57.0, $this->stok($this->galon));
        $this->assertSame(10.0, $this->stok($this->beras));
    }

    public function test_form_tambah_stok_menerima_satuan_lain(): void
    {
        $this->actingAs($this->utama)->post(route('admin-utama.stok.tambah.simpan', ['lokasi' => $this->pusat->id]), [
            'bahan_baku_id' => $this->beras->id, 'jumlah' => '2 x 500 gram',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1.0, $this->stok($this->beras));
    }

    public function test_isian_per_baris_dokumen_ikut_dikonversi_satuannya(): void
    {
        $permintaan = \App\Models\Permintaan::create(['nomor' => 'PR-1', 'cabang_id' => $this->pusat->id, 'status' => 'menunggu']);
        $detail = \App\Models\PermintaanDetail::create(['permintaan_id' => $permintaan->id, 'bahan_baku_id' => $this->galon->id, 'qty_diminta' => 1000]);

        $hasil = JumlahPintar::isianPerDetail([$detail->id => ['qty' => '500 ml'], 999 => ['qty' => '2x3']], \App\Models\PermintaanDetail::class);

        $this->assertSame(0.5, $hasil[$detail->id]['qty']);
        $this->assertSame(6.0, $hasil[999]['qty']);
    }

    public function test_excel_stok_minimum_membaca_kolom_satuan(): void
    {
        $pratinjau = $this->actingAs($this->utama)->post(route('admin-utama.excel.pratinjau', 'stok-minimum'), ['file' => $this->file([
            ['Lokasi', 'Bahan', 'Satuan', 'Minimum'], ['GP', 'Beras', 'gram', 500], ['GP', 'Air Galon', 'liter', '2x19'],
        ])])->assertOk();
        $this->post(route('admin-utama.excel.simpan', 'stok-minimum'), ['kunci' => $pratinjau->viewData('kunci')])->assertSessionHas('sukses');

        $min = fn (BahanBaku $b) => (float) \App\Models\BahanBakuMinimum::where('bahan_baku_id', $b->id)->value('minimum');
        $this->assertSame(500.0, $min($this->beras));
        $this->assertSame(38000.0, $min($this->galon));
    }
}
