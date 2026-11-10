<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Permintaan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\Impor\ImporExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Permintaan cabang dari Excel: templat berisi usulan, baris salah dilewati, satu file = satu permintaan, dan Excel satu permintaan. */
class ImporPermintaanTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private User $admin;
    private BahanBaku $nori;
    private BahanBaku $mie;
    private BahanBaku $gula;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

        $kg = Satuan::create(['nama' => 'kg']);
        $kering = Kategori::create(['nama' => 'Kering', 'urutan' => 1]);
        $manis = Kategori::create(['nama' => 'Manis', 'urutan' => 2]);
        $buat = fn (string $nama, Kategori $k) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $k->id, 'satuan_dasar_id' => $kg->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
        $this->nori = $buat('Nori', $kering);
        $this->mie = $buat('Mie ramen', $kering);
        $this->gula = $buat('Gula pasir', $manis);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 0.4, 'diperbarui_pada' => now()]);
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 1]);
    }

    private function csv(string $isi): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('permintaan.csv', $isi);
    }

    private function pratinjau(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('cabang.permintaan.impor.pratinjau'), ['file' => $file]);
    }

    private function simpan(TestResponse $pratinjau, array $tebakan = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('cabang.permintaan.impor.simpan'), ['kunci' => $pratinjau->viewData('kunci'), 'tebakan' => $tebakan]);
    }

    private function bacaUnduhan(TestResponse $respons): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'minta').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return IOFactory::load($berkas)->getSheet(0)->toArray(null, true, false);
    }

    /** @return array<int, float> [bahan_baku_id => qty diminta] */
    private function diminta(Permintaan $p): array
    {
        return $p->detail()->pluck('qty_diminta', 'bahan_baku_id')->map(fn ($q) => (float) $q)->all();
    }

    public function test_tombol_impor_dan_popup_unggah(): void
    {
        $this->actingAs($this->admin)->get(route('cabang.permintaan'))
            ->assertOk()
            ->assertSee(route('cabang.permintaan.impor'), false);

        $this->get(route('cabang.permintaan.impor'))
            ->assertOk()
            ->assertSee('Unduh Templat')
            ->assertSee('usulan')
            ->assertSee(route('cabang.permintaan.impor.templat'), false);
    }

    public function test_templat_berisi_semua_bahan_dengan_usulan_untuk_yang_di_bawah_minimum(): void
    {
        $isi = $this->bacaUnduhan($this->actingAs($this->admin)->get(route('cabang.permintaan.impor.templat'))->assertOk());

        $this->assertSame(['Kategori', 'Bahan', 'Satuan', 'Sisa Stok', 'Minimum', 'Jumlah', 'Alasan'], $isi[0]);
        $this->assertEquals([
            ['Kering', 'Mie ramen', 'kg', 0, null, null, null],
            ['Kering', 'Nori', 'kg', 0.4, 1, 1, 'Stok di bawah minimum'],
            ['Manis', 'Gula pasir', 'kg', 0, null, null, null],
        ], array_slice($isi, 1, 3));
    }

    public function test_templat_langsung_diunggah_menjadi_permintaan_usulan(): void
    {
        $respons = $this->actingAs($this->admin)->get(route('cabang.permintaan.impor.templat'));
        $berkas = tempnam(sys_get_temp_dir(), 'minta').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());
        $buku = IOFactory::load($berkas);
        $buku->getSheet(0)->setCellValue('F2', '2x2,5');
        (new Xlsx($buku))->save($berkas);

        $pratinjau = $this->pratinjau(new UploadedFile($berkas, 'templat.xlsx', null, null, true));
        $this->assertSame(2, $pratinjau->viewData('hitung')[ImporExcel::STATUS_SIAP]);
        $this->simpan($pratinjau)->assertRedirect(route('cabang.permintaan'))->assertSessionHas('sukses');

        $permintaan = Permintaan::sole();
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $permintaan->status);
        $this->assertSame([$this->nori->id => 1.0, $this->mie->id => 5.0], collect($this->diminta($permintaan))->sortKeys()->all());
        $this->assertSame('Stok di bawah minimum', $permintaan->detail()->where('bahan_baku_id', $this->nori->id)->value('alasan'));
    }

    public function test_tabel_sendiri_baris_salah_dilewati_sisanya_diajukan(): void
    {
        $pratinjau = $this->pratinjau($this->csv("Item,Qty,Unit,Keterangan\nNori,3,kg,Ramai\nSabun cuci,2,,\nMie ramen,banyak,,\nGula pasir,0,,\nNori,500 gram,,\n"))
            ->assertOk();
        $status = $pratinjau->viewData('baris')->pluck('status', 'baris')->sortKeys()->all();
        $this->assertSame(ImporExcel::STATUS_SIAP, $status[2]);
        $this->assertSame(ImporExcel::STATUS_SALAH, $status[3]);
        $this->assertSame(ImporExcel::STATUS_SALAH, $status[4]);
        $this->assertSame(ImporExcel::STATUS_DILEWATI, $status[5]);

        $this->simpan($pratinjau)->assertSessionHas('sukses');

        $permintaan = Permintaan::sole();
        $this->assertSame([$this->nori->id => 3.5], $this->diminta($permintaan));
        $this->assertSame('Ramai', $permintaan->detail()->value('alasan'));
        $this->assertSame($this->admin->id, $permintaan->diajukan_oleh);
    }

    public function test_salah_ketik_ditanya_dan_jawaban_ya_dipakai(): void
    {
        $pratinjau = $this->pratinjau($this->csv("Bahan,Jumlah\nMie ramn,2\n"));
        $tebakan = $pratinjau->viewData('tebakan');
        $this->assertSame('Mie ramen', $tebakan[0]['saran']);

        $this->simpan($pratinjau, [$tebakan[0]['kunci'] => 'terima'])->assertSessionHas('sukses');
        $this->assertSame([$this->mie->id => 2.0], $this->diminta(Permintaan::sole()));
    }

    public function test_tanpa_baris_yang_bisa_diajukan_tidak_membuat_permintaan(): void
    {
        $this->simpan($this->pratinjau($this->csv("Bahan,Jumlah\nNori,0\nSabun cuci,2\n")))->assertSessionHas('galat');
        $this->assertSame(0, Permintaan::count());
    }

    public function test_excel_satu_permintaan_bisa_diunggah_lagi(): void
    {
        $this->simpan($this->pratinjau($this->csv("Bahan,Jumlah,Alasan\nNori,3,Ramai\nMie ramen,1,\n")));
        $permintaan = Permintaan::sole();

        $this->actingAs($this->admin)->get(route('cabang.permintaan.lihat', $permintaan))
            ->assertSee(route('cabang.permintaan.excel', $permintaan), false);

        $respons = $this->get(route('cabang.permintaan.excel', $permintaan))->assertOk();
        $isi = $this->bacaUnduhan($respons);
        $this->assertSame(['Bahan', 'Satuan', 'Diminta', 'Disetujui', 'Dikirim', 'Status', 'Alasan'], $isi[4]);
        $this->assertEquals(['Nori', 'kg', 3, null, null], array_slice($isi[5], 0, 5));
        $this->assertSame('Ramai', $isi[5][6]);

        $berkas = tempnam(sys_get_temp_dir(), 'minta').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());
        $this->simpan($this->pratinjau(new UploadedFile($berkas, 'ulang.xlsx', null, null, true)))->assertSessionHas('sukses');
        $this->assertSame([$this->nori->id => 3.0, $this->mie->id => 1.0], $this->diminta(Permintaan::latest('id')->first()));

        $lain = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->actingAs(User::factory()->adminCabang($lain->id)->create())->get(route('cabang.permintaan.excel', $permintaan))->assertNotFound();
    }
}
