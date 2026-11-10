<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Impor & ekspor Excel data master (gaya Majoo). */
class ExcelMasterTest extends TestCase
{
    use RefreshDatabase;

    private const JUDUL_BAHAN = 'nama,kategori,satuan_dasar,satuan_beli,isi_per_satuan_beli,toleransi_persen';

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        Pengaturan::create(['kunci' => 'toleransi_default', 'nilai' => '3']);
        $this->adminUtama = User::factory()->adminUtama()->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering', 'urutan' => 1])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000,
            'toleransi_persen' => 5,
        ]);
    }

    private function csv(string $judul, array $baris, string $nama = 'data.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, $judul."\n".implode("\n", $baris)."\n");
    }

    private function pratinjau(string $master, UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.pratinjau', $master), ['file' => $file]);
    }

    private function simpan(string $master, TestResponse $pratinjau): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.simpan', $master), ['kunci' => $pratinjau->viewData('kunci')]);
    }

    /** @return array<int, string> [nomor baris => status] */
    private function statusBaris(TestResponse $pratinjau): array
    {
        return $pratinjau->viewData('baris')->pluck('status', 'baris')->sortKeys()->all();
    }

    private function bukaExcel(TestResponse $respons): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return IOFactory::load($berkas)->getSheet(0)->toArray(null, true, false);
    }

    public function test_daftar_master_punya_tombol_impor_ekspor_dan_tambah(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.bahan-baku'))
            ->assertOk()
            ->assertSeeInOrder([
                'href="'.route('admin-utama.excel.unggah', 'bahan-baku').'" data-popup', 'Impor',
                'href="'.route('admin-utama.excel.ekspor', 'bahan-baku').'" download', 'Ekspor',
                'href="'.route('admin-utama.bahan-baku.tambah').'"',
            ], false);

        foreach (['satuan', 'kategori', 'kategori-menu', 'menu'] as $master) {
            $this->get(route('admin-utama.'.$master))->assertOk()->assertSee(route('admin-utama.excel.unggah', $master), false);
        }

        foreach (['lokasi', 'pengguna'] as $master) {
            $this->get(route('admin-utama.'.$master))->assertOk()
                ->assertSee(route('admin-utama.excel.ekspor', $master), false)
                ->assertDontSee(route('admin-utama.excel.unggah', $master), false);
            $this->get(route('admin-utama.excel.unggah', $master))->assertNotFound();
        }

        $this->get(route('admin-utama.excel.unggah', 'bahan-baku'))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee(route('admin-utama.excel.templat', 'bahan-baku'), false)
            ->assertSee('Isi Kolom');
    }

    public function test_impor_bahan_baku_membuat_kategori_dan_satuan_baru_dan_melewati_yang_sudah_ada(): void
    {
        $pratinjau = $this->pratinjau('bahan-baku', $this->csv(self::JUDUL_BAHAN, [
            'Kol,Sayur,gram,ikat,250,',
            'Wortel,sayur,gram,kg,1000,10',
            'nori,Kering,gram,kg,1000,',
            'Garam,Bumbu,gram,,,',
            'Kecap,Bumbu,ml,ml,2,',
            'Saus,Bumbu,ml,botol,600,200',
            'Kol,Sayur,gram,kg,1000,',
            ',Bumbu,gram,,,',
        ], 'bahan.csv'))->assertOk()->assertSee('Cek Bahan Baku');

        $this->assertSame([
            2 => 'siap', 3 => 'siap', 4 => 'dilewati', 5 => 'siap',
            6 => 'salah', 7 => 'salah', 8 => 'salah', 9 => 'salah',
        ], $this->statusBaris($pratinjau));

        $pratinjau->assertSee('Kategori baru: Sayur')
            ->assertSee('Satuan baru: ikat')
            ->assertSee('Satuan beli sama dengan satuan dasar, jadi isinya harus 1.')
            ->assertSee('Toleransi harus angka 0 sampai 100.')
            ->assertSee('Nama sama dengan baris 2.')
            ->assertSee('Nama wajib diisi.')
            ->assertSee('Sudah ada.')
            ->assertSeeText('Simpan 3 data');

        $this->simpan('bahan-baku', $pratinjau)
            ->assertRedirect(route('admin-utama.bahan-baku'))
            ->assertSessionHas('sukses', '3 bahan baku disimpan, 2 kategori baru, 1 satuan baru. 5 baris dilewati.');

        $wortel = BahanBaku::where('nama', 'Wortel')->sole();
        $this->assertSame('10.00', $wortel->toleransi_persen);

        $kol = BahanBaku::where('nama', 'Kol')->sole();
        $this->assertSame($wortel->kategori_id, $kol->kategori_id);
        $this->assertSame('ikat', $kol->satuanBeli->nama);
        $this->assertSame('250.000', $kol->isi_per_satuan_beli);
        $this->assertSame('3.00', $kol->toleransi_persen);

        $garam = BahanBaku::where('nama', 'Garam')->sole();
        $this->assertSame($garam->satuan_dasar_id, $garam->satuan_beli_id);

        $this->assertSame(['Kering', 'Sayur', 'Bumbu'], Kategori::orderBy('urutan')->pluck('nama')->all());
        $this->assertSame('impor.bahan-baku', LogPerubahan::latest('id')->value('aksi'));
    }

    public function test_ekspor_bahan_baku_bisa_diimpor_ulang_tanpa_ganda(): void
    {
        $respons = $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.excel.ekspor', 'bahan-baku'))
            ->assertOk()
            ->assertDownload('bahan-baku-'.now()->format('Y-m-d').'.xlsx');

        $isi = $this->bukaExcel($respons);
        $this->assertSame(['Nama', 'Kategori', 'Satuan'], $isi[0]);
        $this->assertEquals(['Nori', 'Kering', 'kg'], $isi[1]);

        $berkas = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        $pratinjau = $this->pratinjau('bahan-baku', new UploadedFile($berkas, 'bahan.xlsx', null, null, true))->assertOk();
        $this->assertSame([2 => 'dilewati'], $this->statusBaris($pratinjau));
    }

    public function test_impor_menu_tanpa_resep_dengan_kategori_menu_baru(): void
    {
        KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);

        $pratinjau = $this->pratinjau('menu', $this->csv('nama,kategori_menu', [
            'Miso Ramen,Ramen',
            'Chicken Katsu,Rice Bowl',
            'Ocha,',
        ]))->assertOk()->assertSee('Rice Bowl · resep belum diisi')->assertSee('Kategori menu wajib diisi.');

        $this->assertSame([2 => 'siap', 3 => 'siap', 4 => 'salah'], $this->statusBaris($pratinjau));

        $this->simpan('menu', $pratinjau)->assertSessionHas('sukses', '2 menu disimpan, 1 kategori menu baru. 1 baris dilewati.');

        $katsu = Menu::where('nama', 'Chicken Katsu')->sole();
        $this->assertSame('Rice Bowl', $katsu->kategoriMenu->nama);
        $this->assertSame(0, $katsu->resep()->count());

        $this->actingAs($this->adminUtama)->get(route('admin-utama.menu'))->assertOk()->assertSee('Chicken Katsu');
    }

    public function test_impor_kategori_satuan_dan_kategori_menu_serta_supplier_tidak_ada_lagi(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.excel.unggah', 'supplier'))->assertNotFound();

        $pratinjau = $this->pratinjau('kategori', $this->csv('nama', ['Sayur', 'Daging', 'kering']));
        $this->assertSame([2 => 'siap', 3 => 'siap', 4 => 'dilewati'], $this->statusBaris($pratinjau));
        $this->simpan('kategori', $pratinjau);
        $this->assertSame(['Kering', 'Sayur', 'Daging'], Kategori::orderBy('urutan')->pluck('nama')->all());

        $this->simpan('satuan', $this->pratinjau('satuan', $this->csv('nama', ['pcs', 'KG', 'botol'])))
            ->assertSessionHas('sukses', '2 satuan disimpan. 1 baris dilewati.');

        $this->simpan('kategori-menu', $this->pratinjau('kategori-menu', $this->csv('nama', ['Donburi', 'Minuman'])))
            ->assertSessionHas('sukses', '2 kategori menu disimpan.');
        $this->assertSame(['Donburi', 'Minuman'], KategoriMenu::orderBy('urutan')->pluck('nama')->all());
    }

    public function test_ekspor_lokasi_dan_pengguna_tanpa_password_dan_hanya_admin_utama(): void
    {
        $lokasi = Cabang::create(['kode' => 'JB', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $kasir = User::factory()->adminCabang($lokasi->id)->create(['nama' => 'Rani']);

        $isi = $this->bukaExcel($this->actingAs($this->adminUtama)->get(route('admin-utama.excel.ekspor', 'lokasi'))->assertOk());
        $this->assertEquals(['JB', 'Cabang Jati', 'Cabang', null, 'Aktif'], $isi[1]);

        $isi = $this->bukaExcel($this->get(route('admin-utama.excel.ekspor', 'pengguna'))->assertOk());
        $this->assertSame(['Nama', 'Email', 'Peran', 'Lokasi', 'Status'], $isi[0]);
        $this->assertContains(['Rani', $kasir->email, 'Admin Cabang', 'Cabang Jati', 'Aktif'], $isi);

        $this->app->forgetScopedInstances();
        $this->actingAs($kasir)->get(route('admin-utama.excel.ekspor', 'bahan-baku'))->assertForbidden();
        $this->actingAs($kasir)->post(route('admin-utama.excel.pratinjau', 'satuan'), ['file' => $this->csv('nama', ['pcs'])])->assertForbidden();
    }

    private function simpanBerkas(TestResponse $respons): string
    {
        $berkas = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        return $berkas;
    }

    public function test_templat_berisi_contoh_data_biasa_tabel_excel_dan_petunjuk(): void
    {
        $buku = IOFactory::load($this->simpanBerkas($this->actingAs($this->adminUtama)->get(route('admin-utama.excel.templat', 'menu'))->assertOk()));
        $lembar = $buku->getSheet(0);

        $this->assertSame(['Nama', 'Kategori Menu'], $lembar->rangeToArray('A1:B1')[0]);
        $this->assertSame(['Shoyu Ramen', 'Ramen'], $lembar->rangeToArray('A2:B2')[0]);
        $this->assertSame(['Gyudon', 'Donburi'], $lembar->rangeToArray('A5:B5')[0]);
        $this->assertFalse($lembar->getStyle('A2')->getFont()->getItalic(), 'contoh bukan tulisan miring abu-abu');
        $this->assertSame('', $lembar->getComment('A2')->getText()->getPlainText());
        $this->assertSame(['Tabel1'], $lembar->getTableNames());
        $this->assertSame('A1:B10', $lembar->getTableByName('Tabel1')->getRange());

        $petunjuk = $buku->getSheet(1);
        $this->assertSame(['Kolom', 'Cara isi', 'Contoh'], $petunjuk->rangeToArray('A1:C1')[0]);
        $this->assertSame(['Tabel2'], $petunjuk->getTableNames());
    }

    public function test_setiap_templat_berisi_beberapa_baris_contoh_lengkap(): void
    {
        $this->actingAs($this->adminUtama);

        foreach (\App\Services\Impor\DaftarExcelMaster::KELAS as $master => $kelas) {
            $layanan = app($kelas);
            if (! $layanan->bisaImpor()) {
                continue;
            }

            $lembar = IOFactory::load($this->simpanBerkas($this->get(route('admin-utama.excel.templat', $master))->assertOk()))->getSheet(0);
            $kolom = collect($layanan->kolom())->reject(fn (array $t) => ($t['info'] ?? false) || str_starts_with($t['ket'], 'Boleh kosong'))->keys();
            $akhir = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($layanan->kolom()));
            $baris = $lembar->rangeToArray("A2:{$akhir}".$lembar->getHighestRow(), null, true, false);
            $kunci = array_keys($layanan->kolom());

            // Templat yang memakai data sungguhan (bahan, menu, lokasi) bisa kurang dari 3 baris bila datanya masih sedikit.
            $this->assertGreaterThanOrEqual(in_array($master, ['stok-minimum', 'penjualan'], true) ? 1 : 3, count($baris), "Templat {$master} kurang contoh.");
            foreach ($baris as $r) {
                foreach ($kolom as $k) {
                    $this->assertNotNull($r[array_search($k, $kunci, true)], "Templat {$master} kolom {$k} kosong.");
                }
            }
            $this->assertSame(['Tabel1'], $lembar->getTableNames(), "Templat {$master} belum berupa tabel.");
        }
    }

    public function test_semua_templat_yang_langsung_diunggah_berurutan_tersimpan(): void
    {
        $this->actingAs($this->adminUtama);
        \App\Models\Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat Uji', 'jenis' => \App\Models\Cabang::JENIS_PUSAT]);

        foreach (['satuan', 'kategori', 'bahan-baku', 'kategori-menu', 'menu', 'resep', 'stok-minimum'] as $master) {
            if (! array_key_exists($master, \App\Services\Impor\DaftarExcelMaster::KELAS)) {
                continue;
            }
            $berkas = $this->simpanBerkas($this->get(route('admin-utama.excel.templat', $master))->assertOk());
            $pratinjau = $this->pratinjau($master, new UploadedFile($berkas, $master.'.xlsx', null, null, true))->assertOk();

            $status = $this->statusBaris($pratinjau);
            $this->assertNotContains('salah', $status, "Templat {$master} yang langsung diunggah ada yang salah: ".json_encode($status));
            $this->assertNotContains('cek', $status, "Templat {$master} yang langsung diunggah masih bertanya.");
            $this->simpan($master, $pratinjau)->assertSessionHas('sukses');
        }

        foreach (\App\Support\ContohTemplat::BAHAN as [$nama]) {
            $this->assertTrue(\App\Models\BahanBaku::where('nama', $nama)->exists(), "Bahan contoh {$nama} belum tersimpan.");
        }
        foreach (\App\Support\ContohTemplat::MENU as [$nama]) {
            $this->assertTrue(\App\Models\Menu::where('nama', $nama)->exists(), "Menu contoh {$nama} belum tersimpan.");
        }
        $this->assertSame(3, \App\Models\Resep::query()->distinct()->count('menu_id'));
        $this->assertGreaterThanOrEqual(3, \App\Models\BahanBakuMinimum::count());
    }

    public function test_templat_lama_berbaris_contoh_abu_abu_tetap_dilewati(): void
    {
        $this->actingAs($this->adminUtama);
        $layanan = app(\App\Services\Impor\DaftarExcelMaster::KELAS['satuan']);
        $buku = \App\Support\LembarExcel::templat($layanan->judul(), $layanan->kolom(), contoh: true);
        $buku->getSheet(0)->setCellValue('A3', 'pcs');
        $berkas = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($berkas);

        $pratinjau = $this->pratinjau('satuan', new UploadedFile($berkas, 'satuan.xlsx', null, null, true))
            ->assertOk()
            ->assertSee('Baris 2 masih sama dengan baris contoh templat');
        $this->assertSame([3 => 'siap'], $this->statusBaris($pratinjau));
    }
}
