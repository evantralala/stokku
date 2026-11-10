<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use App\Services\Impor\PembersihImpor;
use App\Services\StokService;
use App\Support\SatuanTunggal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Nama lain bahan: diisi di form, dikenali di Excel, diingat dari jawaban "Maksudnya …?", dan ikut ke form (Tempel Daftar). */
class InputPintarTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private User $utama;
    private BahanBaku $telur;
    private BahanBaku $beras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->utama = User::factory()->adminUtama()->create();
        $kategori = Kategori::create(['nama' => 'Pokok', 'urutan' => 1]);
        $bahan = fn (string $nama, string $satuan, ?string $lain = null) => BahanBaku::create(['nama' => $nama, 'nama_lain' => $lain,
            'kategori_id' => $kategori->id, 'toleransi_persen' => 5] + SatuanTunggal::untuk(Satuan::firstOrCreate(['nama' => $satuan])));
        $this->telur = $bahan('Telur Ayam', 'butir', 'telor, egg');
        $this->beras = $bahan('Beras Jepang', 'kg');
    }

    private function file(array $baris): UploadedFile
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->fromArray($baris, null, 'A1', true);
        $berkas = tempnam(sys_get_temp_dir(), 'ip').'.xlsx';
        (new Xlsx($buku))->save($berkas);

        return new UploadedFile($berkas, 'stok.xlsx', null, null, true);
    }

    private function pratinjau(UploadedFile $file)
    {
        return $this->actingAs($this->utama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]),
            ['mode' => 'tambah', 'file' => $file])->assertOk();
    }

    private function stok(BahanBaku $b): float
    {
        return $b->keSatuanBeli(app(StokService::class)->saldo($this->pusat->id, $b->id));
    }

    public function test_form_bahan_menyimpan_nama_lain_yang_dirapikan(): void
    {
        $this->actingAs($this->utama)->put(route('admin-utama.bahan-baku.perbarui', $this->beras), [
            'nama' => 'Beras Jepang', 'nama_lain' => ' beras japan ,  rice;beras japan', 'kategori_id' => $this->beras->kategori_id,
            'satuan_id' => $this->beras->satuan_beli_id, 'toleransi_persen' => '5',
        ])->assertSessionHasNoErrors();

        $this->assertSame('beras japan, rice', $this->beras->fresh()->nama_lain);
    }

    public function test_excel_mengenali_nama_lain_tanpa_bertanya(): void
    {
        $pratinjau = $this->pratinjau($this->file([['Bahan', 'Stok'], ['telor', 30]]));

        $this->assertSame([], $pratinjau->viewData('tebakan'));
        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertSessionHas('sukses');
        $this->assertSame(30.0, $this->stok($this->telur));
    }

    public function test_jawaban_ya_diingat_sebagai_nama_lain(): void
    {
        $pratinjau = $this->pratinjau($this->file([['Bahan', 'Stok'], ['Brs Jepang', 5]]));
        $tebakan = collect($pratinjau->viewData('tebakan'))->firstWhere('saran', 'Beras Jepang');
        $this->assertNotNull($tebakan);

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), [
            'kunci' => $pratinjau->viewData('kunci'), 'tebakan' => [$tebakan['kunci'] => PembersihImpor::TERIMA],
        ])->assertSessionHas('sukses');

        $this->assertSame('Brs Jepang', $this->beras->fresh()->nama_lain);
        $this->assertSame([], $this->pratinjau($this->file([['Bahan', 'Stok'], ['brs jepang', 2]]))->viewData('tebakan'));
    }

    public function test_form_permintaan_membawa_nama_lain_dan_tempel_daftar(): void
    {
        $jbr = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $admin = User::factory()->adminCabang($jbr->id)->create();

        $this->actingAs($admin)->get(route('cabang.permintaan.buat'))->assertOk()
            ->assertSee('data-tempel-daftar', false)
            ->assertSee('"lain":"telor, egg"', false);
    }
}
