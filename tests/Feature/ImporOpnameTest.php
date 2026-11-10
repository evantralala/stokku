<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Notifikasi;
use App\Models\Opname;
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

/** Opname dari Excel: templat berisi stok sistem, satu file = satu opname yang langsung dikirim, dan Excel hasil opname. */
class ImporOpnameTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $admin;
    private Cabang $cabang;
    private BahanBaku $nori;
    private BahanBaku $mie;
    private BahanBaku $gula;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
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

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 2.5, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 4, 'diperbarui_pada' => now()],
        ]);
    }

    private function csv(string $isi): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('opname.csv', $isi);
    }

    private function pratinjau(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('cabang.opname.impor.pratinjau'), ['file' => $file]);
    }

    private function simpan(TestResponse $pratinjau): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('cabang.opname.impor.simpan'), ['kunci' => $pratinjau->viewData('kunci')]);
    }

    private function bacaUnduhan(TestResponse $respons): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'opname').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return IOFactory::load($berkas)->getSheet(0)->toArray(null, true, false);
    }

    public function test_tombol_impor_tampil_dan_hilang_saat_opname_berjalan(): void
    {
        $this->actingAs($this->admin)->get(route('cabang.opname'))
            ->assertOk()
            ->assertSee(route('cabang.opname.impor'), false)
            ->assertSee('Impor Opname');

        $this->get(route('cabang.opname.impor'))
            ->assertOk()
            ->assertSee('Unduh Templat')
            ->assertSee(route('cabang.opname.impor.templat'), false)
            ->assertSee('Bahan yang tidak diisi dianggap 0');

        $this->post(route('cabang.opname.mulai'));
        $this->get(route('cabang.opname'))->assertDontSee(route('cabang.opname.impor'), false);
    }

    public function test_templat_berisi_semua_bahan_dan_stok_sistem_urut_kategori(): void
    {
        $isi = $this->bacaUnduhan($this->actingAs($this->admin)->get(route('cabang.opname.impor.templat'))->assertOk());

        $this->assertSame(['Kategori', 'Bahan', 'Satuan', 'Stok Sistem', 'Hitung Fisik', 'Catatan'], $isi[0]);
        $this->assertEquals([
            ['Kering', 'Mie ramen', 'kg', 4, null, null],
            ['Kering', 'Nori', 'kg', 2.5, null, null],
            ['Manis', 'Gula pasir', 'kg', 0, null, null],
        ], array_slice($isi, 1, 3));
        $this->assertCount(4, array_filter($isi, fn ($r) => array_filter($r) !== []));
    }

    public function test_impor_membuat_opname_langsung_dikirim_dan_bahan_lain_dianggap_nol(): void
    {
        $pratinjau = $this->pratinjau($this->csv("Bahan,Hitung Fisik,Catatan\nNori,\"2,4\",Rak atas\nMie ramen,,\n"))
            ->assertOk()
            ->assertSee('1 bahan tidak ada di file dan dianggap 0: Gula pasir.')
            ->assertSee('Hitung Fisik kosong, dianggap 0.');
        $this->assertSame([ImporExcel::STATUS_SIAP, ImporExcel::STATUS_SIAP], $pratinjau->viewData('baris')->pluck('status')->all());

        $this->simpan($pratinjau)->assertRedirect(route('cabang.opname'))->assertSessionHas('sukses');

        $opname = Opname::sole();
        $this->assertSame(Opname::STATUS_DIKIRIM, $opname->status);
        $this->assertSame($this->admin->id, $opname->petugas_id);
        $this->assertTrue($this->cabang->fresh()->opname_aktif);

        $fisik = $opname->detail()->pluck('qty_fisik', 'bahan_baku_id')->map(fn ($q) => (float) $q)->all();
        $this->assertSame([$this->nori->id => 2.4, $this->mie->id => 0.0, $this->gula->id => 0.0], $fisik + []);
        $this->assertSame('Rak atas', $opname->detail()->where('bahan_baku_id', $this->nori->id)->value('catatan'));
        $this->assertSame(2.5, (float) $opname->detail()->where('bahan_baku_id', $this->nori->id)->value('qty_sistem'));
        $this->assertSame(1, Notifikasi::query()->where('tautan', route('admin-utama.persetujuan.opname', $opname, false))->count());
    }

    public function test_bahan_dobel_dijumlahkan_dan_satuan_dikonversi(): void
    {
        $pratinjau = $this->pratinjau($this->csv("Bahan,Satuan,Hitung Fisik\nNori,kg,1\nNori,gram,500\nMie ramen,kg,2x2\nGula pasir,kg,0\n"));
        $this->simpan($pratinjau)->assertSessionHas('sukses');

        $fisik = Opname::sole()->detail()->pluck('qty_fisik', 'bahan_baku_id')->map(fn ($q) => (float) $q);
        $this->assertSame(1.5, $fisik[$this->nori->id]);
        $this->assertSame(4.0, $fisik[$this->mie->id]);
    }

    public function test_satu_baris_salah_atau_semua_kosong_membatalkan_seluruh_file(): void
    {
        $pratinjau = $this->pratinjau($this->csv("Bahan,Hitung Fisik\nNori,2\nMie ramen,-1\n"));
        $this->assertSame([ImporExcel::STATUS_SALAH, ImporExcel::STATUS_SALAH], $pratinjau->viewData('baris')->pluck('status')->all());
        $pratinjau->assertSee('Opname tidak dibuat karena ada baris lain yang salah.');
        $this->simpan($pratinjau)->assertSessionHas('galat');

        $kosong = $this->pratinjau($this->csv("Bahan,Stok Sistem,Hitung Fisik\nNori,2.5,\nMie ramen,4,\n"));
        $kosong->assertSee('Kolom Hitung Fisik masih kosong semua.');
        $this->simpan($kosong)->assertSessionHas('galat');

        $this->assertSame(0, Opname::count());
        $this->assertFalse($this->cabang->fresh()->opname_aktif);
    }

    public function test_ditolak_bila_opname_berjalan_atau_ada_dokumen_menunggu(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.opname.mulai'));

        $pratinjau = $this->pratinjau($this->csv("Bahan,Hitung Fisik\nNori,2\n"))
            ->assertSee('Opname di lokasi ini masih berjalan.');
        $this->assertSame([ImporExcel::STATUS_SALAH], $pratinjau->viewData('baris')->pluck('status')->all());
        $this->simpan($pratinjau)->assertSessionHas('galat');
        $this->assertSame(1, Opname::count());
    }

    public function test_templat_yang_diisi_lalu_diunggah_tersimpan(): void
    {
        $respons = $this->actingAs($this->admin)->get(route('cabang.opname.impor.templat'));
        $berkas = tempnam(sys_get_temp_dir(), 'opname').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        $buku = IOFactory::load($berkas);
        $lembar = $buku->getSheet(0);
        $lembar->setCellValue('E2', 3);
        $lembar->setCellValue('E3', '2,5');
        (new Xlsx($buku))->save($berkas);

        $pratinjau = $this->pratinjau(new UploadedFile($berkas, 'templat-opname.xlsx', null, null, true));
        $this->simpan($pratinjau)->assertSessionHas('sukses');

        $fisik = Opname::sole()->detail()->pluck('qty_fisik', 'bahan_baku_id')->map(fn ($q) => (float) $q);
        $this->assertSame(3.0, $fisik[$this->mie->id]);
        $this->assertSame(2.5, $fisik[$this->nori->id]);
        $this->assertSame(0.0, $fisik[$this->gula->id]);
    }

    public function test_excel_hasil_opname_menyembunyikan_stok_sistem_sampai_disetujui(): void
    {
        $this->simpan($this->pratinjau($this->csv("Bahan,Hitung Fisik\nNori,2\nMie ramen,4\nGula pasir,1\n")));
        $opname = Opname::sole();

        $this->actingAs($this->admin)->get(route('cabang.opname.lihat', $opname))
            ->assertSee(route('cabang.opname.excel', $opname), false);

        $petugas = $this->bacaUnduhan($this->actingAs($this->admin)->get(route('cabang.opname.excel', $opname))->assertOk());
        $this->assertSame(['Kategori', 'Bahan', 'Satuan', 'Hitung Fisik', 'Catatan'], $petugas[4]);
        $this->assertEquals(['Kering', 'Nori', 'kg', 2, null], $petugas[6]);

        $utama = $this->bacaUnduhan($this->actingAs($this->adminUtama)->get(route('admin-utama.persetujuan.opname.excel', $opname))->assertOk());
        $this->assertSame(['Kategori', 'Bahan', 'Satuan', 'Stok Sistem', 'Hitung Fisik', 'Selisih', 'Catatan'], $utama[4]);
        $this->assertEquals(['Kering', 'Nori', 'kg', 2.5, 2, -0.5, null], $utama[6]);
        $this->assertEquals(['Manis', 'Gula pasir', 'kg', 0, 1, 1, null], $utama[7]);

        $lain = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->actingAs(User::factory()->adminCabang($lain->id)->create())->get(route('cabang.opname.excel', $opname))->assertNotFound();
    }

    public function test_admin_pusat_juga_bisa_impor_opname(): void
    {
        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $adminPusat = User::factory()->adminPusat($pusat->id)->create();

        $pratinjau = $this->actingAs($adminPusat)->post(route('pusat.opname.impor.pratinjau'), ['file' => $this->csv("Bahan,Hitung Fisik\nNori,1\n")]);
        $this->actingAs($adminPusat)->post(route('pusat.opname.impor.simpan'), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertRedirect(route('pusat.opname'))->assertSessionHas('sukses');

        $this->assertSame($pusat->id, Opname::sole()->cabang_id);
        $this->actingAs($this->adminUtama)->post(route('cabang.opname.impor.pratinjau'), ['file' => $this->csv("Bahan,Hitung Fisik\nNori,1\n")])
            ->assertForbidden();
    }
}
