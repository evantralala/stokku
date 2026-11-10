<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Menu;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use App\Services\StokService;
use App\Support\DaftarKontrol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Impor Sekaligus: satu file berlembar Lokasi, Bahan, Menu, Resep, Stok Minimum, Stok. */
class ImporSekaligusTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $gp;
    private User $utama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));
        $this->gp = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->utama = User::factory()->adminUtama()->create();
        Satuan::create(['nama' => 'gram']);
    }

    /** @param  array<string, list<list<mixed>>>  $lembar  nama lembar => baris (baris pertama judul kolom) */
    private function file(array $lembar): UploadedFile
    {
        $buku = new Spreadsheet();
        $buku->removeSheetByIndex(0);
        foreach ($lembar as $nama => $baris) {
            $buku->createSheet()->setTitle($nama)->fromArray($baris, null, 'A1', true);
        }
        $berkas = tempnam(sys_get_temp_dir(), 'sekaligus').'.xlsx';
        (new Xlsx($buku))->save($berkas);

        return new UploadedFile($berkas, 'data-awal.xlsx', null, null, true);
    }

    private function lengkap(): array
    {
        return [
            'Lokasi' => [['Kode', 'Nama', 'Jenis', 'Alamat'], ['JTI', 'Cabang Jati', 'Cabang', null], ['KNG', 'Cabang Kenanga', null, null]],
            'Bahan'  => [['Nama', 'Kategori', 'Satuan'], ['Beras Jepang', 'Bahan Pokok', 'kg'], ['Nori', null, null]],
            'Menu'   => [['Nama', 'Kategori Menu'], ['Sushi Roll', 'Makanan'], ['Ocha', 'Minuman']],
            'Resep'  => [['Menu', 'Bahan', 'Takaran', 'Satuan'], ['Sushi Roll', 'Beras Jepang', 100, 'gram'], ['Sushi Roll', 'Nori', 1, 'pcs']],
            'Stok Minimum' => [['Lokasi', 'Bahan', 'Satuan', 'Minimum'], ['Semua', 'Beras Jepang', 'kg', 0.5], ['JTI', 'Beras Jepang', 'kg', 0.8]],
            'Stok'   => [['Lokasi', 'Bahan', 'Satuan', 'Stok'], ['GP', 'Beras Jepang', 'gram', 1000], ['Semua Cabang', 'Nori', 'pcs', 10],
                ['Cabang Kenanga', 'Nori', 'pcs', 4], [null, 'Beras Jepang', 'gram', 5], ['XYZ', 'Beras Jepang', 'gram', 5]],
        ];
    }

    private function cek(UploadedFile $file, string $mode = 'samakan', ?User $oleh = null): TestResponse
    {
        return $this->actingAs($oleh ?? $this->utama)->post(route('admin-utama.impor-sekaligus.pratinjau'), ['file' => $file, 'mode' => $mode]);
    }

    private function simpan(?User $oleh = null): TestResponse
    {
        return $this->actingAs($oleh ?? $this->utama)->post(route('admin-utama.impor-sekaligus.simpan'), ['kunci' => session('impor_sekaligus.kunci')]);
    }

    private function saldo(string $kode, string $bahan): float
    {
        return app(StokService::class)->saldo(Cabang::where('kode', $kode)->value('id'), BahanBaku::where('nama', $bahan)->value('id'));
    }

    private function minimum(string $kode, string $bahan): ?float
    {
        $nilai = BahanBakuMinimum::where('cabang_id', Cabang::where('kode', $kode)->value('id'))
            ->where('bahan_baku_id', BahanBaku::where('nama', $bahan)->value('id'))->value('minimum');

        return $nilai === null ? null : (float) $nilai;
    }

    public function test_templat_berisi_semua_lembar(): void
    {
        $respons = $this->actingAs($this->utama)->get(route('admin-utama.impor-sekaligus.templat'))->assertOk();
        $berkas = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        $this->assertSame(['Lokasi', 'Bahan', 'Menu', 'Resep', 'Stok Minimum', 'Stok', 'Petunjuk'], IOFactory::load($berkas)->getSheetNames());
    }

    public function test_pratinjau_tidak_mengubah_data_lalu_simpan_semua_lembar(): void
    {
        $this->cek($this->file($this->lengkap()))->assertOk()
            ->assertSee('Cek Impor Sekaligus')->assertSee('Stok Minimum')->assertSee('Salah Isi')
            ->assertSee('Lokasi XYZ', false);

        $this->assertSame(1, Cabang::count());
        $this->assertSame(0, BahanBaku::count());
        $this->assertSame(0, Menu::count());

        $this->simpan()->assertRedirect(route('admin-utama.dashboard'))->assertSessionHas('sukses');

        $this->assertSame(3, Cabang::count());
        $nori = BahanBaku::where('nama', 'Nori')->with('satuanDasar', 'kategori')->firstOrFail();
        $this->assertSame('pcs', $nori->satuanDasar->nama);
        $this->assertSame('Lainnya', $nori->kategori->nama);
        $beras = BahanBaku::where('nama', 'Beras Jepang')->with('satuanDasar', 'satuanBeli')->sole();
        $this->assertSame(['gram', 'kg'], [$beras->satuanDasar->nama, $beras->satuanBeli->nama]);
        $this->assertSame(2, Menu::count());
        $this->assertSame(2, Resep::count());

        $this->assertSame(500.0, $this->minimum('GP', 'Beras Jepang'));
        $this->assertSame(800.0, $this->minimum('JTI', 'Beras Jepang'));
        $this->assertSame(500.0, $this->minimum('KNG', 'Beras Jepang'));

        $this->assertSame(1000.0, $this->saldo('GP', 'Beras Jepang'));
        $this->assertSame(10.0, $this->saldo('JTI', 'Nori'));
        $this->assertSame(4.0, $this->saldo('KNG', 'Nori'));
        $this->assertSame(0.0, $this->saldo('GP', 'Nori'));
    }

    public function test_unggah_ulang_dengan_ganti_tidak_dobel_dan_tambah_menambah(): void
    {
        $this->cek($this->file($this->lengkap()));
        $this->simpan();

        $this->cek($this->file($this->lengkap()))->assertOk()->assertSee('Resep sama dengan yang berlaku, tidak diubah.');
        $this->simpan()->assertSessionHas('sukses');
        $this->assertSame(3, Cabang::count());
        $this->assertSame(2, BahanBaku::count());
        $this->assertSame(2, Resep::count());
        $this->assertSame(1000.0, $this->saldo('GP', 'Beras Jepang'));

        $this->cek($this->file(['Stok' => $this->lengkap()['Stok']]), 'tambah')->assertOk()->assertSee('Tambah');
        $this->simpan()->assertSessionHas('sukses');
        $this->assertSame(2000.0, $this->saldo('GP', 'Beras Jepang'));
        $this->assertSame(8.0, $this->saldo('KNG', 'Nori'));
    }

    public function test_lembar_boleh_bolong_dan_resep_tanpa_bahan_di_sistem_salah(): void
    {
        $this->cek($this->file([
            'Menu'  => [['Nama'], ['Ramen']],
            'Resep' => [['Menu', 'Bahan', 'Takaran', 'Satuan'], ['Ramen', 'Mie Kuning', 100, 'gram']],
            'Catatan Saya' => [['Apa saja'], ['bebas']],
        ]))->assertOk()->assertSee('Catatan Saya');

        $this->simpan()->assertRedirect(route('admin-utama.dashboard'));
        $this->assertSame('Lainnya', Menu::where('nama', 'Ramen')->firstOrFail()->kategoriMenu->nama);
        $this->assertSame(0, Resep::count());
    }

    public function test_gudang_pusat_kedua_dan_lokasi_kosong_salah_isi(): void
    {
        $this->cek($this->file([
            'Lokasi' => [['Kode', 'Nama', 'Jenis'], ['GP2', 'Gudang Kedua', 'Gudang Pusat'], ['JTI', 'Cabang Jati', null]],
        ]))->assertOk()->assertSee('Sudah ada Gudang Pusat aktif');

        $this->simpan();
        $this->assertFalse(Cabang::where('kode', 'GP2')->exists());
        $this->assertTrue(Cabang::where('kode', 'JTI')->exists());
    }

    public function test_file_tanpa_lembar_dikenali_ditolak(): void
    {
        $this->cek($this->file(['Sheet1' => [['Apa'], ['saja']]]))
            ->assertRedirect(route('admin-utama.dashboard'))->assertSessionHas('galat');
    }

    public function test_admin_pusat_hanya_bisa_bila_diizinkan(): void
    {
        $pusat = User::factory()->adminPusat($this->gp->id)->create();

        $this->actingAs($pusat)->get(route('admin-utama.impor-sekaligus'))->assertForbidden();
        $this->actingAs($pusat)->get(route('pusat.dashboard'))->assertOk()->assertDontSee('Impor Sekaligus');
        $this->actingAs($this->utama)->get(route('admin-utama.dashboard'))->assertOk()->assertSee('Impor Sekaligus');

        $isian = collect(DaftarKontrol::menuPeran(User::ROLE_ADMIN_PUSAT))->filter(fn ($d) => isset($d['awalan']))
            ->map(fn ($d, $k) => ['lihat' => $k === 'utama-impor-sekaligus' ? '1' : '0'])->all();
        $this->actingAs($this->utama)->put(route('admin-utama.kontrol-sistem.akses.simpan', 'pusat'), ['akses' => $isian]);

        $this->actingAs($pusat)->get(route('admin-utama.impor-sekaligus'))->assertOk();
        $this->actingAs($pusat)->get(route('pusat.dashboard'))->assertOk()->assertSee('Impor Sekaligus');
        $this->cek($this->file(['Menu' => [['Nama', 'Kategori Menu'], ['Ocha', 'Minuman']]]), 'samakan', $pusat)->assertOk();
        $this->simpan($pusat)->assertRedirect(route('pusat.dashboard'));
        $this->assertTrue(Menu::where('nama', 'Ocha')->exists());
    }

    public function test_impor_stok_minimum_sendiri_menerima_semua_cabang_dan_baris_langsung_menang(): void
    {
        $this->cek($this->file(['Lokasi' => $this->lengkap()['Lokasi'], 'Bahan' => $this->lengkap()['Bahan']]));
        $this->simpan();

        $pratinjau = $this->actingAs($this->utama)->post(route('admin-utama.excel.pratinjau', 'stok-minimum'), ['file' => $this->file([
            'Stok Minimum' => [['Lokasi', 'Bahan', 'Satuan', 'Minimum'], ['Semua Cabang', 'Beras Jepang', 'kg', 0.3], ['KNG', 'Beras Jepang', 'kg', 0.9], [null, 'Nori', 'pcs', 2]],
        ])])->assertOk()->assertSee('Salah Isi');
        $this->post(route('admin-utama.excel.simpan', 'stok-minimum'), ['kunci' => $pratinjau->viewData('kunci')])->assertSessionHas('sukses');

        $this->assertNull($this->minimum('GP', 'Beras Jepang'));
        $this->assertSame(300.0, $this->minimum('JTI', 'Beras Jepang'));
        $this->assertSame(900.0, $this->minimum('KNG', 'Beras Jepang'));
        $this->assertNull($this->minimum('JTI', 'Nori'));
    }

    public function test_bahan_yang_masih_ditanyakan_tetap_dikenali_lembar_berikutnya(): void
    {
        \App\Models\Kategori::create(['nama' => 'Bumbu & Saus', 'urutan' => 1]);

        $respons = $this->cek($this->file([
            'Bahan' => [['Nama', 'Kategori', 'Satuan Dasar'], ['Jahe Merah', 'Bumbu', 'gram']],
            'Stok'  => [['Lokasi', 'Bahan', 'Satuan', 'Stok'], ['GP', 'Jahe Merah', 'gram', 50]],
        ]))->assertOk()->assertDontSee('tidak ada di daftar bahan baku');

        $kunci = collect($respons->viewData('tebakan'))->pluck('kunci')->first();
        $this->actingAs($this->utama)->post(route('admin-utama.impor-sekaligus.simpan'), [
            'kunci' => session('impor_sekaligus.kunci'), 'tebakan' => [$kunci => \App\Services\Impor\PembersihImpor::TERIMA],
        ])->assertSessionHas('sukses');

        $this->assertSame('Bumbu & Saus', BahanBaku::where('nama', 'Jahe Merah')->firstOrFail()->kategori->nama);
        $this->assertSame(50.0, $this->saldo('GP', 'Jahe Merah'));
    }

    public function test_semua_data_dalam_satu_lembar_ditumpuk_dengan_atau_tanpa_judul_bagian(): void
    {
        $l = $this->lengkap();
        $satu = [['LOKASI'], ...$l['Lokasi'], [null], ['BAHAN'], ...$l['Bahan'], [null], ...$l['Menu'], [null],
            ['Resep'], ...$l['Resep'], [null], ...$l['Stok Minimum'], [null], ['STOK'], ...$l['Stok']];

        $respons = $this->cek($this->file(['Data Awal' => $satu]))->assertOk()->assertSee('Lembar Data Awal');
        $lembar = $respons->viewData('lembar');
        $this->assertSame(['lokasi', 'bahan', 'menu', 'resep', 'stok_minimum', 'stok'], $lembar->keys()->all());
        $this->assertSame(2, $lembar['menu']['hitung']['siap']);
        $salahStok = collect($lembar['stok']['baris'])->where('status', 'salah')->pluck('baris')->sort()->values()->all();
        $this->assertSame([29, 30], $salahStok);

        $this->simpan()->assertSessionHas('sukses');
        $this->assertSame(3, Cabang::count());
        $this->assertSame(2, Menu::count());
        $this->assertSame(800.0, $this->minimum('JTI', 'Beras Jepang'));
        $this->assertSame(1000.0, $this->saldo('GP', 'Beras Jepang'));
        $this->assertSame(4.0, $this->saldo('KNG', 'Nori'));
    }

    public function test_satu_lembar_bertumpuk_bisa_digabung_dengan_lembar_lain(): void
    {
        $l = $this->lengkap();

        $lembar = $this->cek($this->file([
            'Master' => [...$l['Lokasi'], [null], ...$l['Bahan']],
            'Stok'   => $l['Stok'],
        ]))->assertOk()->viewData('lembar');

        $this->assertSame(['lokasi', 'bahan', 'stok'], $lembar->keys()->all());
    }
}
