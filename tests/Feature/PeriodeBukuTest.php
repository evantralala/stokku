<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\PeriodeBuku;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\KirimanService;
use App\Services\PenjualanService;
use App\Services\PeriodeBukuService;
use App\Services\StokService;
use App\Services\TambahStokService;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Tutup buku (Mulai Periode Baru): arsip Excel + cadangan, transaksi dikosongkan, stok akhir jadi stok awal, dan penjagaan tanggal lama. */
class PeriodeBukuTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminJati;
    private User $adminKenanga;
    private Cabang $pusat;
    private Cabang $jati;
    private Cabang $kenanga;
    private BahanBaku $nori;
    private Menu $shoyu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(21, 0));
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(PengaturanSeeder::class);

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->jati = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->kenanga = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create(['password' => 'rahasia-utama']);
        User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminJati = User::factory()->adminCabang($this->jati->id)->create();
        $this->adminKenanga = User::factory()->adminCabang($this->kenanga->id)->create();

        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => Satuan::create(['nama' => 'gram'])->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ])->fresh();
        $this->shoyu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1])->id]);
        Resep::create(['menu_id' => $this->shoyu->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => 10, 'berlaku_sejak' => now()->subDays(5)->toDateString()]);

        $tambah = app(TambahStokService::class);
        $tambah->tambah($this->adminUtama, $this->pusat, $this->nori, 5, null);
        $tambah->tambah($this->adminUtama, $this->jati, $this->nori, 2, null);
        app(PenjualanService::class)->catat($this->adminJati, $this->jati, now()->toDateString(), [$this->shoyu->id => 3]);
    }

    private function tutup(array $isi = [])
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.kontrol-sistem.periode.simpan'), $isi + [
            'nama' => 'Oktober 2026', 'konfirmasi' => 'tutup buku', 'password' => 'rahasia-utama',
        ]);
    }

    private function saldo(Cabang $lokasi): float
    {
        return app(StokService::class)->saldo($lokasi->id, $this->nori->id);
    }

    public function test_tutup_buku_mengarsipkan_mengosongkan_dan_stok_akhir_jadi_stok_awal(): void
    {
        $this->assertSame(1970.0, $this->saldo($this->jati));

        $this->tutup()->assertRedirect(route('admin-utama.pengaturan').'#periode')->assertSessionHas('sukses');

        $periode = PeriodeBuku::sole();
        $this->assertSame('Oktober 2026', $periode->nama);
        $this->assertSame(1, $periode->ringkasan['Hari penjualan (per cabang)']);
        $this->assertSame([(string) $this->jati->id => now()->toDateString()], $periode->penjualan_terakhir);
        Storage::disk('local')->assertExists(PeriodeBuku::FOLDER.'/'.$periode->berkas_excel);
        Storage::disk('local')->assertExists(PeriodeBuku::FOLDER.'/'.$periode->berkas_cadangan);

        foreach (['penjualan_harian', 'penjualan_detail', 'tambah_stok', 'barang_keluar', 'notifikasi'] as $tabel) {
            $this->assertSame(0, DB::table($tabel)->count(), $tabel.' harus kosong');
        }

        $this->assertSame(5000.0, $this->saldo($this->pusat));
        $this->assertSame(1970.0, $this->saldo($this->jati));
        $this->assertSame([StokMutasi::JENIS_STOK_AWAL], StokMutasi::query()->distinct()->pluck('jenis')->all());
        $this->assertSame(2, StokMutasi::query()->sah()->where('dokumen_tipe', PeriodeBuku::DOKUMEN_TIPE)->where('dokumen_id', $periode->id)->count());

        $this->assertSame(3, Cabang::count());
        $this->assertSame(1, BahanBaku::count());
        $this->assertSame(1, Menu::count());
        $this->assertSame(1, Resep::count());
        $this->assertSame(4, User::count());
        $this->assertDatabaseHas('log_perubahan', ['aksi' => 'periode.tutup', 'user_id' => $this->adminUtama->id]);

        $buku = IOFactory::load(Storage::disk('local')->path(PeriodeBuku::FOLDER.'/'.$periode->berkas_excel));
        $this->assertSame(['Ringkasan', 'Stok Akhir', 'Masuk & Keluar', 'Penjualan', 'Barang Rusak', 'Kiriman', 'Opname'], $buku->getSheetNames());
        $this->assertSame('Shoyu Ramen', $buku->getSheetByName('Penjualan')->getCell('D6')->getValue());
    }

    public function test_kartu_stok_periode_baru_menampilkan_stok_awal(): void
    {
        $this->tutup();

        $this->actingAs($this->adminJati)->get(route('cabang.stok.riwayat', $this->nori))
            ->assertOk()
            ->assertSee('Stok Awal')
            ->assertSee('Stok awal Oktober 2026');
    }

    public function test_belum_bisa_tutup_buku_bila_ada_dokumen_berjalan(): void
    {
        app(KirimanService::class)->kirimLangsung($this->jati, $this->pusat, $this->adminJati, [$this->nori->id => 1], 'Retur');

        $this->assertSame(['1 kiriman belum diterima cabang'], app(PeriodeBukuService::class)->penghalang());
        $this->actingAs($this->adminUtama)->get(route('admin-utama.kontrol-sistem.periode'))
            ->assertOk()
            ->assertSee('Belum bisa tutup buku')
            ->assertSee('1 kiriman belum diterima cabang')
            ->assertDontSee('Ketik TUTUP BUKU');

        $this->tutup()->assertSessionHas('galat');
        $this->assertSame(0, PeriodeBuku::count());
        $this->assertSame(1, DB::table('penjualan_harian')->count());
    }

    public function test_konfirmasi_dan_password_wajib_benar(): void
    {
        $this->tutup(['konfirmasi' => 'TUTUP', 'password' => 'salah'])->assertSessionHasErrors(['konfirmasi', 'password']);
        $this->tutup(['nama' => ''])->assertSessionHasErrors('nama');
        $this->assertSame(0, PeriodeBuku::count());

        $this->actingAs($this->adminJati)->post(route('admin-utama.kontrol-sistem.periode.simpan'), [
            'nama' => 'X', 'konfirmasi' => 'TUTUP BUKU', 'password' => 'password',
        ])->assertForbidden();
    }

    public function test_penjualan_yang_sudah_diarsipkan_tidak_bisa_dicatat_lagi(): void
    {
        $this->tutup();
        $simpan = fn (User $u, string $tanggal) => $this->actingAs($u)->post(route('cabang.penjualan.simpan'), [
            'tanggal' => $tanggal, 'porsi' => [$this->shoyu->id => '2'],
        ]);

        $simpan($this->adminJati, now()->toDateString())->assertSessionHasErrors(['tanggal' => PeriodeBukuService::PESAN_PENJUALAN_TERTUTUP]);
        $simpan($this->adminKenanga, now()->subDay()->toDateString())->assertSessionHasErrors('tanggal');
        $this->assertSame(1970.0, $this->saldo($this->jati));

        $simpan($this->adminKenanga, now()->toDateString())->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('penjualan_harian')->count());
    }

    public function test_penjualan_hari_ini_yang_ikut_tutup_buku_tampil_diarsipkan_bukan_belum_dicatat(): void
    {
        $this->tutup();

        $this->actingAs($this->adminJati)->get(route('cabang.dashboard'))
            ->assertOk()->assertSee('Sudah diarsipkan saat tutup buku')->assertDontSee('Catat Sekarang');
        $this->get(route('cabang.penjualan'))
            ->assertOk()->assertSee('ikut tutup buku')->assertDontSee('Catat Hari Ini')->assertDontSee('belum dicatat');

        $this->actingAs($this->adminKenanga)->get(route('cabang.dashboard'))->assertOk()->assertSee('Catat Sekarang');
    }

    public function test_tanggal_sebelum_tutup_buku_ditolak(): void
    {
        $layanan = app(PeriodeBukuService::class);
        $this->assertFalse($layanan->tanggalTertutup(now()->subDays(3)->toDateString()));

        $this->tutup();

        $this->assertTrue($layanan->tanggalTertutup(now()->subDay()->toDateString()));
        $this->assertFalse($layanan->tanggalTertutup(now()->toDateString()));
    }

    public function test_arsip_bisa_diunduh_dan_tercatat_di_log(): void
    {
        $this->tutup();
        $periode = PeriodeBuku::sole();

        $this->actingAs($this->adminUtama)->get(route('admin-utama.kontrol-sistem.periode.unduh', [$periode, 'excel']))->assertOk()->assertDownload($periode->berkas_excel);
        $this->get(route('admin-utama.kontrol-sistem.periode.unduh', [$periode, 'cadangan']))->assertOk()->assertDownload($periode->berkas_cadangan);
        $this->get('/admin-utama/kontrol-sistem/periode-buku/'.$periode->id.'/lain')->assertNotFound();
        $this->assertSame(2, DB::table('log_perubahan')->where('aksi', 'periode.unduh')->count());
    }

    public function test_pengaturan_dan_transaksi_tetap_jalan_bila_tabel_periode_belum_ada(): void
    {
        Schema::drop('periode_buku');
        $this->app->forgetInstance(PeriodeBukuService::class);

        $this->actingAs($this->adminUtama)->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSee('Belum pernah tutup buku')
            ->assertSee(PeriodeBukuService::PESAN_BELUM_TERPASANG)
            ->assertDontSee(route('admin-utama.kontrol-sistem.periode'), false);

        $this->get(route('admin-utama.kontrol-sistem.periode'))
            ->assertRedirect(route('admin-utama.pengaturan').'#periode')
            ->assertSessionHas('galat', PeriodeBukuService::PESAN_BELUM_TERPASANG);

        $this->assertFalse(app(PeriodeBukuService::class)->tanggalTertutup(now()->subDays(30)->toDateString()));
    }

    public function test_pengaturan_menampilkan_periode_berjalan_dan_arsip(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSee('Periode Buku')
            ->assertSee('Belum pernah tutup buku')
            ->assertSee('Mulai Periode Baru');

        $this->get(route('admin-utama.kontrol-sistem.periode'))
            ->assertOk()
            ->assertSee('Ketik TUTUP BUKU')
            ->assertSee(now()->translatedFormat('F Y'));

        $this->tutup();

        $this->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSee('Oktober 2026')
            ->assertSee(route('admin-utama.kontrol-sistem.periode.unduh', [PeriodeBuku::sole(), 'excel']))
            ->assertSee('Periode berjalan sejak '.now()->translatedFormat('j M Y'));
    }
}
