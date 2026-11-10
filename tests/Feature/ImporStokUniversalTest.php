<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Impor stok "universal": file Unduh Excel halaman Stok, templat yang kolom Stok Sistem-nya diubah, dan tabel buatan sendiri. */
class ImporStokUniversalTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private User $adminUtama;
    private BahanBaku $beras;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $pokok = Kategori::create(['nama' => 'Bahan Pokok', 'urutan' => 1]);
        $this->beras = BahanBaku::create(['nama' => 'Beras Jepang', 'kategori_id' => $pokok->id, 'satuan_dasar_id' => $gram->id,
            'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5]);
        $this->nori = BahanBaku::create(['nama' => 'Nori', 'kategori_id' => $pokok->id, 'satuan_dasar_id' => $gram->id,
            'satuan_beli_id' => $gram->id, 'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5]);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 500, 'diperbarui_pada' => now()],
        ]);
    }

    private function saldo(BahanBaku $bahan): float
    {
        return app(StokService::class)->saldo($this->pusat->id, $bahan->id);
    }

    /** Unduh file dari alamat, ubah selnya lewat $ubah, lalu jadikan file unggahan. */
    private function unduhLaluUbah(string $alamat, callable $ubah): UploadedFile
    {
        $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        $respons = $this->actingAs($this->adminUtama)->get($alamat)->assertOk();
        file_put_contents($berkas, $respons->streamedContent());

        $buku = IOFactory::load($berkas);
        $ubah($buku->getSheet(0));
        (new Xlsx($buku))->save($berkas);

        return new UploadedFile($berkas, 'stok.xlsx', null, null, true);
    }

    private function cari(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $lembar, string $teks): array
    {
        foreach ($lembar->toArray(null, false, false, true) as $r => $sel) {
            foreach ($sel as $kol => $nilai) {
                if ($nilai === $teks) {
                    return [$kol, $r];
                }
            }
        }

        $this->fail("Sel {$teks} tidak ada.");
    }

    private function pratinjau(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => $file])->assertOk();
    }

    private function simpan(TestResponse $pratinjau): TestResponse
    {
        return $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')]);
    }

    private function statusBahan(TestResponse $pratinjau): array
    {
        return $pratinjau->viewData('baris')->mapWithKeys(fn ($r) => [$r['data']['bahan'] => $r['status']])->sortKeys()->all();
    }

    public function test_file_unduh_excel_halaman_stok_bisa_diunggah_balik(): void
    {
        $file = $this->unduhLaluUbah(route('admin-utama.stok.unduh', ['lokasi' => $this->pusat->id]), function ($lembar) {
            [$kol, $r] = $this->cari($lembar, 'Beras Jepang');
            $lembar->setCellValue('D'.$r, 6.5);
        });

        $pratinjau = $this->pratinjau($file);
        $this->assertSame(['Beras Jepang' => 'siap', 'Nori' => 'dilewati'], $this->statusBahan($pratinjau));
        $pratinjau->assertSee('8 → 6,5 kg (-1,5)');

        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(6500.0, $this->saldo($this->beras));
        $this->assertTrue(StokMutasi::where('bahan_baku_id', $this->beras->id)->where('jumlah', -1500)->exists(), 'tercatat di kartu stok');
    }

    public function test_file_unduh_excel_tanpa_perubahan_semua_tidak_berubah(): void
    {
        $file = $this->unduhLaluUbah(route('admin-utama.stok.unduh', ['lokasi' => $this->pusat->id]), fn () => null);

        $this->assertSame(['Beras Jepang' => 'dilewati', 'Nori' => 'dilewati'], $this->statusBahan($this->pratinjau($file)));
    }

    public function test_stok_berubah_setelah_file_diunduh_tetap_memakai_angka_file_dengan_catatan(): void
    {
        $file = $this->unduhLaluUbah(route('admin-utama.stok.unduh', ['lokasi' => $this->pusat->id]), function ($lembar) {
            [, $r] = $this->cari($lembar, 'Beras Jepang');
            $lembar->setCellValue('D'.$r, 6);
        });
        DB::table('stok_saldo')->where('bahan_baku_id', $this->beras->id)->update(['saldo' => 7000]);

        $pratinjau = $this->pratinjau($file);
        $this->assertSame('siap', $this->statusBahan($pratinjau)['Beras Jepang']);
        $pratinjau->assertSee('7 → 6 kg (-1)')->assertSee('Stok sempat diubah di sistem setelah file diunduh (8 → 7 kg).');

        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(6000.0, $this->saldo($this->beras));
    }

    public function test_templat_lama_dengan_stok_sistem_diubah_tetap_terbaca(): void
    {
        $buku = new Spreadsheet();
        $buku->getActiveSheet()->fromArray([
            ['Kategori', 'Bahan', 'Satuan', 'Stok Sistem', 'Stok Sebenarnya', 'Keterangan'],
            ['Bahan Pokok', 'Nori', 'gram', 350, null, null],
            ['Bahan Pokok', 'Beras Jepang', 'kg', 8, null, null],
        ]);
        \App\Services\Impor\ImporPenyesuaianStokService::tulisStokUnduh($buku, $this->pusat, [['Nori', 500], ['Beras Jepang', 8]]);
        $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        (new Xlsx($buku))->save($berkas);

        $pratinjau = $this->pratinjau(new UploadedFile($berkas, 'lama.xlsx', null, null, true));
        $this->assertSame(['Beras Jepang' => 'dilewati', 'Nori' => 'siap'], $this->statusBahan($pratinjau));
        $pratinjau->assertSee('Stok baru diambil dari kolom Stok Sistem yang diubah.');

        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(350.0, $this->saldo($this->nori));
    }

    public function test_csv_lama_stok_sistem_diubah_tanpa_data_sistem_diberi_pesan_jelas(): void
    {
        $file = UploadedFile::fake()->createWithContent('stok.csv', "kategori,bahan,satuan,stok_sistem,stok_sebenarnya,keterangan\nBahan Pokok,Beras Jepang,kg,5,,\n");

        $pratinjau = $this->pratinjau($file);
        $this->assertSame(['Beras Jepang' => 'salah'], $this->statusBahan($pratinjau));
        $pratinjau->assertSee('File ini format lama');
    }

    public function test_tabel_sendiri_dengan_judul_di_atas_dan_nama_kolom_lain(): void
    {
        $buku = new Spreadsheet();
        $lembar = $buku->getActiveSheet();
        $lembar->fromArray([
            ['Laporan Stok Gudang'],
            ['Dihitung Senin pagi'],
            [],
            ['No', 'Nama Item', 'Qty', 'Unit', 'Gudang', 'Status'],
            [1, 'beras jepang', '7,5', 'KG', 'GP', 'Aman'],
            [2, 'Nori', 0.4, 'kg', 'Gudang Pusat', 'x'],
            [3, 'Beras Jepang', 1, 'kg', 'JTI', ''],
        ]);
        $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        (new Xlsx($buku))->save($berkas);

        $pratinjau = $this->pratinjau(new UploadedFile($berkas, 'hitung.xlsx', null, null, true));
        $baris = $pratinjau->viewData('baris')->keyBy('baris');

        $this->assertSame('siap', $baris[5]['status']);
        $this->assertSame(7.5, $baris[5]['data']['stok_baru']);
        $this->assertSame('salah', $baris[6]['status'], 'satuan kg bukan satuan Nori');
        $this->assertSame('dilewati', $baris[7]['status']);
        $pratinjau->assertSee('Baris untuk lokasi JTI, bukan Gudang Pusat.');

        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(7500.0, $this->saldo($this->beras));
    }

    public function test_satuan_dasar_dikonversi_ke_satuan_beli(): void
    {
        $file = UploadedFile::fake()->createWithContent('stok.csv', "Bahan,Jumlah,Satuan\nBeras Jepang,2500,gram\n");

        $pratinjau = $this->pratinjau($file)->assertSee('2.500 gram = 2,5 kg');
        $this->simpan($pratinjau)->assertSessionHas('sukses');
        $this->assertSame(2500.0, $this->saldo($this->beras));
    }

    public function test_file_tanpa_kolom_bahan_dan_stok_ditolak_dengan_jelas(): void
    {
        $file = UploadedFile::fake()->createWithContent('acak.csv', "Nama,Telepon\nBudi,0812\n");

        $this->actingAs($this->adminUtama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => $file])
            ->assertRedirect()
            ->assertSessionHas('galat', 'Baris judul kolom tidak ditemukan. Pastikan ada kolom Bahan, Stok.');
    }

    public function test_tabel_sendiri_dengan_judul_apa_pun_ditebak_dari_isinya(): void
    {
        $kolomNama = UploadedFile::fake()->createWithContent('a.csv', "Kategori,Nama,Stok,Column1,Column2\nBahan Pokok,Beras Jepang,9,,\nBahan Pokok,Nori,600,x,123\n");
        $pratinjau = $this->pratinjau($kolomNama);
        $this->assertSame(['siap' => 2], $pratinjau->viewData('baris')->countBy('status')->all());

        $judulAneh = UploadedFile::fake()->createWithContent('b.csv', "Laporan gudang\n\nKelompok,Barang Saya,Sisa Hitung\nBahan Pokok,Beras Jepang,9\nBahan Pokok,Nori,600\n");
        $pratinjau = $this->pratinjau($judulAneh)->assertSee('8 → 9 kg');
        $this->assertSame([4, 5], $pratinjau->viewData('baris')->pluck('baris')->sort()->values()->all());

        $tanpaJudul = UploadedFile::fake()->createWithContent('c.csv', "Bahan Pokok,Beras Jepang,9\nBahan Pokok,Nori,600\n");
        $this->simpan($this->pratinjau($tanpaJudul))->assertSessionHas('sukses');
        $this->assertSame(9000.0, $this->saldo($this->beras));
        $this->assertSame(600.0, $this->saldo($this->nori));
    }

    public function test_tebakan_kolom_angka_ganda_minta_judul_kolom(): void
    {
        $file = UploadedFile::fake()->createWithContent('d.csv', "Barang Saya,A,B\nBeras Jepang,9,2\n");

        $this->actingAs($this->adminUtama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => $file])
            ->assertRedirect()
            ->assertSessionHas('galat', 'Ada beberapa kolom angka (A, B), jadi kolom Stok tidak bisa ditebak. Ganti judul kolomnya menjadi "Stok".');
    }

    public function test_file_sama_dengan_stok_sekarang_diberi_tahu_dan_halaman_cek_tidak_ikut_segar(): void
    {
        $file = UploadedFile::fake()->createWithContent('e.csv', "Bahan,Stok\nBeras Jepang,8\n");

        $this->pratinjau($file)->assertSee('Tidak ada data yang berubah')->assertDontSee('name="url-versi"', false);
        $this->get(route('admin-utama.stok'))->assertSee('name="url-versi"', false);
    }
}
