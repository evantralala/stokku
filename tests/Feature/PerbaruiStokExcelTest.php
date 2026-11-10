<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Notifikasi;
use App\Models\PenyesuaianStok;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Pembaruan jumlah stok banyak bahan lewat ekspor/impor Excel di halaman Stok (Admin Utama dan Admin Pusat). */
class PerbaruiStokExcelTest extends TestCase
{
    use RefreshDatabase;

    private const JUDUL = 'kategori,bahan,satuan,stok_sistem,stok_sebenarnya,keterangan';

    private Cabang $pusat;
    private Cabang $cabang;
    private User $adminUtama;
    private User $adminPusat;
    private BahanBaku $beras;
    private BahanBaku $nori;
    private BahanBaku $salmon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $pokok = Kategori::create(['nama' => 'Bahan pokok', 'urutan' => 1]);
        $kering = Kategori::create(['nama' => 'Kering', 'urutan' => 2]);

        $bahan = fn (string $nama, Kategori $k, Satuan $beli, int $isi) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $k->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $beli->id,
            'isi_per_satuan_beli' => $isi, 'toleransi_persen' => 5,
        ]);
        $this->beras = $bahan('Beras Jepang', $pokok, $kg, 1000);
        $this->salmon = $bahan('Salmon', $pokok, $kg, 1000);
        $this->nori = $bahan('Nori', $kering, $gram, 1);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->salmon->id, 'saldo' => 2000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 500, 'diperbarui_pada' => now()],
        ]);

        $this->izinkanUbahStokPusat();
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    private function csv(array $baris): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('stok.csv', self::JUDUL."\n".implode("\n", $baris)."\n");
    }

    private function statusBaris(TestResponse $pratinjau): array
    {
        return $pratinjau->viewData('baris')->pluck('status', 'baris')->sortKeys()->all();
    }

    public function test_tombol_dan_popup_perbarui_lewat_excel(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSee(route('admin-utama.stok.excel', ['lokasi' => $this->pusat->id]), false)
            ->assertSee('Perbarui Lewat Excel');

        $this->get(route('admin-utama.stok.excel', ['lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('Perbarui Lewat Excel')
            ->assertSee('Unduh Templat')
            ->assertSee(route('admin-utama.stok.excel.unduh', ['lokasi' => $this->pusat->id]), false)
            ->assertSee(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), false)
            ->assertSee('Tabel sendiri juga bisa');
    }

    public function test_templat_stok_berformat_sama_dengan_unduh_excel_berisi_contoh_per_kategori(): void
    {
        $this->actingAs($this->adminUtama);
        $nama = \App\Support\BukuExcel::namaBerkas('stok', 'Gudang Pusat', now()->toDateString());

        $templat = $this->get(route('admin-utama.stok.excel.unduh', ['lokasi' => $this->pusat->id]))->assertOk()->assertDownload($nama);
        $unduh = $this->get(route('admin-utama.stok.unduh', ['lokasi' => $this->pusat->id]))->assertOk()->assertDownload($nama);

        $baca = function ($respons) {
            $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
            file_put_contents($berkas, $respons->streamedContent());

            return array_slice(IOFactory::load($berkas)->getSheet(0)->toArray(null, true, false), 4);
        };

        $isiTemplat = $baca($templat);
        $isiUnduh = $baca($unduh);
        $judul = ['Kategori', 'Bahan', 'Satuan', 'Stok', 'Menunggu', 'Minimum', 'Pakai per Hari', 'Cukup (Hari)', 'Status', 'Keterangan'];
        $this->assertSame($judul, $isiTemplat[0]);
        $this->assertSame($judul, $isiUnduh[0]);

        $this->assertEquals(['Bahan pokok', 'Beras Jepang', 'kg', 8], array_slice($isiUnduh[1], 0, 4), 'Unduh Excel = stok sebenarnya');
        $this->assertEquals(['Bahan pokok', 'Beras Jepang', 'kg', 12], array_slice($isiTemplat[1], 0, 4), 'templat = angka contoh');
        $this->assertSame(['Bahan pokok', 'Kering'], array_values(array_unique(array_column(array_slice($isiTemplat, 1), 0))));
    }

    public function test_templat_stok_yang_langsung_diunggah_bisa_disimpan(): void
    {
        $respons = $this->actingAs($this->adminUtama)->get(route('admin-utama.stok.excel.unduh', ['lokasi' => $this->pusat->id]));
        $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        $pratinjau = $this->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => new UploadedFile($berkas, 'stok.xlsx', null, null, true)])
            ->assertOk()
            ->assertSee('8 → 12 kg (+4)');
        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])->assertSessionHas('sukses');

        $this->assertSame(12000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_unduh_isi_unggah_lalu_simpan_menjadi_penyesuaian_stok(): void
    {
        $respons = $this->actingAs($this->adminUtama)->get(route('admin-utama.stok.unduh', ['lokasi' => $this->pusat->id]));
        $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        $buku = IOFactory::load($berkas);
        $lembar = $buku->getSheet(0);
        foreach ($lembar->toArray(null, false, false, true) as $r => $sel) {
            if ($sel['B'] === 'Beras Jepang') {
                $lembar->setCellValue('D'.$r, '7,5');
            }
        }
        IOFactory::createWriter($buku, 'Xlsx')->save($berkas);

        $pratinjau = $this->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => new UploadedFile($berkas, 'stok.xlsx', null, null, true)])
            ->assertOk()
            ->assertSee('8 → 7,5 kg (-0,5)')
            ->assertSee('Sama dengan stok sekarang.');
        $this->assertSame(['siap' => 1, 'dilewati' => 2], $pratinjau->viewData('baris')->countBy('status')->sortKeys()->reverse()->all());

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSessionHas('sukses', 'Stok 1 bahan diperbarui. 2 baris tidak diubah.');

        $this->assertSame(7500.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame(2000.0, $this->saldo($this->pusat, $this->salmon));

        $dokumen = PenyesuaianStok::sole();
        $this->assertSame('Pembaruan stok lewat Excel (stok.xlsx)', $dokumen->keterangan);
        $this->assertSame(StokMutasi::JENIS_PENYESUAIAN, StokMutasi::where('dokumen_tipe', PenyesuaianStok::DOKUMEN_TIPE)->sole()->jenis);

        $this->assertSame(0, Notifikasi::where('user_id', $this->adminUtama->id)->count(), 'Admin Utama tidak diberi tahu perubahannya sendiri');
    }

    public function test_baris_salah_isi_dan_stok_yang_berubah_sejak_diunduh_tetap_memakai_angka_file(): void
    {
        DB::table('stok_saldo')->where('bahan_baku_id', $this->nori->id)->update(['saldo' => 450]);

        $pratinjau = $this->actingAs($this->adminUtama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => $this->csv([
            'Bahan pokok,Beras Jepang,kg,8,6,',
            'Bahan pokok,Beras jepang,kg,8,5,',
            'Kering,Nori,gram,500,300,',
            'Kering,Wasabi,gram,0,-1,',
            'Bahan pokok,Salmon,kg,,1,',
        ])])->assertOk();

        $this->assertSame([2 => 'siap', 3 => 'dilewati', 4 => 'siap', 5 => 'salah', 6 => 'siap'], $this->statusBaris($pratinjau));
        $pratinjau->assertSee('Digabung ke baris 2.')
            ->assertSee('Stok sempat diubah di sistem setelah file diunduh (500 → 450 gram). Bila disimpan, diganti dengan angka di file.')
            ->assertSee('Bahan Wasabi tidak ada di daftar bahan baku.')
            ->assertSee('Stok baru harus angka 0 atau lebih');

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])->assertSessionHas('sukses');
        $this->assertSame(11000.0, $this->saldo($this->pusat, $this->beras), 'baris bahan yang sama dijumlahkan');
        $this->assertSame(300.0, $this->saldo($this->pusat, $this->nori), 'angka di file tetap dipakai, tidak perlu unduh ulang');
        $this->assertSame(1000.0, $this->saldo($this->pusat, $this->salmon), 'tanpa stok sistem tidak ada pembanding, stok baru tetap dipakai');
    }

    public function test_stok_bertambah_langsung_siap_disimpan(): void
    {
        $this->actingAs($this->adminUtama);

        $pratinjau = $this->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => $this->csv(['Bahan pokok,Salmon,kg,2,3,'])])
            ->assertSee('2 → 3 kg (+1)');
        $this->assertSame([2 => 'siap'], $this->statusBaris($pratinjau));

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])->assertSessionHas('sukses');
        $this->assertSame(3000.0, $this->saldo($this->pusat, $this->salmon));
        $this->assertSame(1000.0, (float) PenyesuaianStok::sole()->selisih);
    }

    public function test_admin_utama_memperbarui_lokasi_pilihan_tanpa_notifikasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok.excel', ['lokasi' => $this->cabang->id]))
            ->assertOk()
            ->assertSee(route('admin-utama.stok.excel.unduh', ['lokasi' => $this->cabang->id]), false);

        $pratinjau = $this->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->cabang->id]), ['mode' => 'samakan', 
            'file' => $this->csv(['Bahan pokok,Beras Jepang,kg,0,4,Stok awal cabang']),
        ])->assertOk();

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->cabang->id]), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->cabang->id]));

        $this->assertSame(4000.0, $this->saldo($this->cabang, $this->beras));
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame('Stok awal cabang', PenyesuaianStok::sole()->keterangan);
        $this->assertSame(0, Notifikasi::count());
    }

    public function test_simpan_dibatalkan_seluruhnya_saat_lokasi_sedang_opname(): void
    {
        $pratinjau = $this->actingAs($this->adminUtama)->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['mode' => 'samakan', 'file' => $this->csv([
            'Bahan pokok,Beras Jepang,kg,8,6,',
            'Kering,Nori,gram,500,300,',
        ])]);

        $this->pusat->update(['opname_aktif' => true]);

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSessionHas('galat');

        $this->assertSame(0, PenyesuaianStok::count());
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame(500.0, $this->saldo($this->pusat, $this->nori));
    }

    public function test_pratinjau_yang_sudah_tidak_berlaku_harus_diunggah_ulang(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => 'kunci-lama'])
            ->assertSessionHas('galat', 'Pratinjau sudah tidak berlaku. Unggah ulang filenya.');

        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_admin_pusat_tidak_bisa_perbarui_stok_lewat_excel_walau_diizinkan(): void
    {
        $this->assertFalse(Route::has('pusat.stok.excel'));
        $this->actingAs($this->adminPusat)->get(route('pusat.stok'))->assertOk()->assertDontSee('Perbarui Lewat Excel');
        $this->get('/pusat/stok/excel')->assertNotFound();
    }

    public function test_bawaan_tambah_ke_stok_menambah_dan_tercatat_sebagai_tambah_stok(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok.excel', ['lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('Tambah')
            ->assertSee('Ganti')
            ->assertSee('name="mode" value="tambah" checked', false);

        $berkas = UploadedFile::fake()->createWithContent('datang.csv', "Bahan,Stok,Satuan\nBeras Jepang,2,kg\nNori,250,gram\nSalmon,,kg\n");
        $pratinjau = $this->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['file' => $berkas])
            ->assertOk()
            ->assertSee('8 → 10 kg (+2)')
            ->assertSee('500 → 750 gram (+250)');
        $this->assertSame(['siap' => 2, 'dilewati' => 1], $pratinjau->viewData('baris')->countBy('status')->sortKeys()->reverse()->all());

        $this->post(route('admin-utama.stok.excel.simpan', ['lokasi' => $this->pusat->id]), ['kunci' => $pratinjau->viewData('kunci')])
            ->assertSessionHas('sukses', 'Stok 2 bahan ditambah. 1 baris tidak diubah.');

        $this->assertSame(10000.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame(750.0, $this->saldo($this->pusat, $this->nori));
        $this->assertSame(2, StokMutasi::where('jenis', StokMutasi::JENIS_TAMBAH_LANGSUNG)->count());
        $this->assertSame(0, PenyesuaianStok::count(), 'tambah bukan penyesuaian, jadi tidak masuk kolom Lebih di laporan');
    }

    public function test_tambah_dengan_file_unduh_excel_tanpa_diubah_menambahkan_angkanya_dan_menolak_minus(): void
    {
        $respons = $this->actingAs($this->adminUtama)->get(route('admin-utama.stok.unduh', ['lokasi' => $this->pusat->id]));
        $berkas = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());
        $buku = IOFactory::load($berkas);
        foreach ($buku->getSheet(0)->toArray(null, false, false, true) as $r => $sel) {
            if ($sel['B'] === 'Salmon') {
                $buku->getSheet(0)->setCellValue('D'.$r, -1);
            }
        }
        IOFactory::createWriter($buku, 'Xlsx')->save($berkas);

        $pratinjau = $this->post(route('admin-utama.stok.excel.pratinjau', ['lokasi' => $this->pusat->id]), ['file' => new UploadedFile($berkas, 'stok.xlsx', null, null, true)])
            ->assertOk()
            ->assertSee('8 → 16 kg (+8)')
            ->assertSee('Jumlah tambah harus angka lebih dari 0');
        $this->assertEquals(['salah' => 1, 'siap' => 2], $pratinjau->viewData('baris')->countBy('status')->sortKeys()->all());
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }
}
