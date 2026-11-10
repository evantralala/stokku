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
use App\Services\LaporanService;
use App\Support\Periode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tombol Ubah di halaman Stok (penyesuaian stok langsung, hanya Admin Utama) dan di daftar bahan baku. */
class UbahStokTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $cabang;
    private User $adminUtama;
    private User $adminCabang;
    private User $adminPusat;
    private BahanBaku $beras;
    private BahanBaku $salmon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Bahan pokok', 'urutan' => 1]);

        $this->beras = BahanBaku::create([
            'nama' => 'Beras Jepang', 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->salmon = BahanBaku::create([
            'nama' => 'Salmon', 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->salmon->id, 'saldo' => 2000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
        ]);

        $this->izinkanUbahStokPusat();
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    public function test_setiap_bahan_aktif_di_halaman_stok_punya_tombol_ubah(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]), false)
            ->assertSee(route('admin-utama.stok.ubah', [$this->salmon, 'lokasi' => $this->pusat->id]), false)
            ->assertSee('aria-label="Ubah stok Beras Jepang"', false);

        $this->beras->update(['aktif' => false]);

        $this->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSee('Beras Jepang')
            ->assertDontSee(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]), false);

        $this->get(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]))->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))->assertSessionHas('galat');
    }

    public function test_form_ubah_menampilkan_stok_sekarang_sebagai_isian_awal(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('8 kg')
            ->assertSee('name="stok_lama" value="8000"', false)
            ->assertSee('name="stok_baru"', false)
            ->assertDontSee('kedaluwarsa');

        $this->get(route('admin-utama.stok.intip', [$this->beras, 'lokasi' => $this->pusat->id]))->assertSee(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]), false);
        $this->get(route('admin-utama.stok.riwayat', [$this->beras, 'lokasi' => $this->pusat->id]))->assertSee(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]), false);
    }

    public function test_mengurangi_stok_tercatat_sebagai_penyesuaian(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]), ['stok_lama' => '8000', 'stok_baru' => '6,5', 'keterangan' => 'Hitung ulang rak'])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSessionHas('sukses');

        $this->assertSame(6500.0, $this->saldo($this->pusat, $this->beras));

        $dokumen = PenyesuaianStok::sole();
        $this->assertEquals(8000, (float) $dokumen->stok_lama);
        $this->assertEquals(6500, (float) $dokumen->stok_baru);
        $this->assertEquals(-1500, (float) $dokumen->selisih);
        $this->assertSame('Hitung ulang rak', $dokumen->keterangan);
        $this->assertStringStartsWith('PS-GP-', $dokumen->nomor);

        $mutasi = StokMutasi::where('dokumen_tipe', PenyesuaianStok::DOKUMEN_TIPE)->sole();
        $this->assertSame(StokMutasi::JENIS_PENYESUAIAN, $mutasi->jenis);
        $this->assertSame(StokMutasi::STATUS_SAH, $mutasi->status);
        $this->assertEquals(-1500, (float) $mutasi->jumlah);

        $this->assertSame(0, Notifikasi::where('user_id', $this->adminUtama->id)->count());

        $this->get(route('admin-utama.stok.riwayat', [$this->beras, 'lokasi' => $this->pusat->id]))->assertSee('Penyesuaian Stok')->assertSee($dokumen->nomor);

        $hilang = app(LaporanService::class)->pemakaianBahan($this->pusat, Periode::antara(now(), now()))->firstWhere('bahan.id', $this->beras->id);
        $this->assertSame(1500.0, $hilang['hilang']);
    }

    public function test_admin_utama_mengubah_stok_lokasi_terpilih_tanpa_notifikasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.ubah.simpan', ['bahanBaku' => $this->beras->id, 'lokasi' => $this->cabang->id]), [
                'stok_lama' => '8000', 'stok_baru' => '3',
            ])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->cabang->id]))
            ->assertSessionHas('sukses');

        $this->assertSame(3000.0, $this->saldo($this->cabang, $this->beras));
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame(0, Notifikasi::count());
    }

    public function test_jumlah_sama_atau_negatif_ditolak(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]), ['stok_lama' => '8000', 'stok_baru' => '8'])
            ->assertSessionHasErrors('stok_baru');

        $this->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]), ['stok_lama' => '8000', 'stok_baru' => '-1'])
            ->assertSessionHasErrors('stok_baru');

        $this->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]), ['stok_lama' => '8000', 'stok_baru' => ''])
            ->assertSessionHasErrors('stok_baru');

        $this->assertSame(0, PenyesuaianStok::count());
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_stok_yang_berubah_sejak_form_dibuka_tidak_ditimpa(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]), ['stok_lama' => '7000', 'stok_baru' => '5'])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSessionHas('galat');

        $this->assertSame(0, PenyesuaianStok::count());
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_ubah_stok_ditolak_saat_lokasi_sedang_opname(): void
    {
        $this->pusat->update(['opname_aktif' => true]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]), ['stok_lama' => '8000', 'stok_baru' => '5'])
            ->assertSessionHas('galat');

        $this->assertSame(0, PenyesuaianStok::count());
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_stok_bertambah_atau_berkurang_tersimpan_tanpa_isian_tambahan(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.ubah.simpan', [$this->salmon, 'lokasi' => $this->pusat->id]), ['stok_lama' => '2000', 'stok_baru' => '3'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $this->assertSame(3000.0, $this->saldo($this->pusat, $this->salmon));
        $this->assertSame(1000.0, (float) PenyesuaianStok::sole()->selisih);

        $this->post(route('admin-utama.stok.ubah.simpan', [$this->salmon, 'lokasi' => $this->pusat->id]), ['stok_lama' => '3000', 'stok_baru' => '2,5'])
            ->assertSessionHas('sukses');

        $this->assertSame(2500.0, $this->saldo($this->pusat, $this->salmon));
    }

    public function test_admin_cabang_tidak_bisa_mengubah_stok_langsung(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertDontSee('aria-label="Ubah stok Beras Jepang"', false)
            ->assertDontSee('Tambah Stok')
            ->assertDontSee('Perbarui Lewat Excel');

        $this->get(route('cabang.stok.intip', $this->beras))->assertOk()->assertDontSee('Ubah Stok')->assertDontSee('Tambah Stok');
        $this->get(route('cabang.stok.riwayat', $this->beras))->assertOk()->assertDontSee('Ubah Stok')->assertDontSee('Tambah Stok');

        $this->get('/cabang/stok/'.$this->beras->id.'/ubah')->assertNotFound();
        $this->post('/cabang/stok/'.$this->beras->id.'/ubah', ['stok_lama' => '8000', 'stok_baru' => '1'])->assertNotFound();
        $this->get('/cabang/stok/tambah')->assertNotFound();
        $this->post('/cabang/stok/tambah', ['bahan_baku_id' => $this->beras->id, 'jumlah' => '5'])->assertNotFound();
        $this->get('/cabang/stok/excel')->assertNotFound();
        $this->post('/cabang/stok/excel', [])->assertNotFound();

        $this->assertSame(0, PenyesuaianStok::count());
        $this->assertSame(8000.0, $this->saldo($this->cabang, $this->beras));
    }

    public function test_daftar_bahan_baku_punya_tombol_ubah_yang_tampil_di_hp(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.bahan-baku'))
            ->assertOk()
            ->assertSee('class="ka kartu-aksi kartu-aksi-hp"', false)
            ->assertSee('aria-label="Ubah Beras Jepang"', false)
            ->assertSee(route('admin-utama.bahan-baku.ubah', $this->beras), false);
    }

    public function test_admin_pusat_tidak_bisa_mengubah_stok_walau_tambah_stok_diizinkan(): void
    {
        $this->actingAs($this->adminPusat)->get(route('pusat.stok'))
            ->assertOk()
            ->assertDontSee('aria-label="Ubah stok Beras Jepang"', false)
            ->assertDontSee('aria-label="Kosongkan stok Beras Jepang"', false);

        $this->get('/pusat/stok/'.$this->beras->id.'/ubah')->assertNotFound();
        $this->post('/pusat/stok/'.$this->beras->id.'/ubah', ['stok_lama' => '8000', 'stok_baru' => '1'])->assertNotFound();
        $this->get(route('pusat.stok.intip', $this->beras))->assertDontSee('/ubah', false);
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));
    }

    public function test_kurangi_menghitung_stok_baru_dan_menolak_melebihi_stok(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok.ubah', [$this->beras, 'lokasi' => $this->pusat->id]))
            ->assertSee('name="cara" value="kurangi" checked', false)
            ->assertSee('name="jumlah_kurang"', false);

        $this->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]),
            ['cara' => 'kurangi', 'stok_lama' => '8000', 'jumlah_kurang' => '9'])
            ->assertSessionHasErrors(['jumlah_kurang' => 'Pengurangan melebihi stok sekarang (8 kg).']);
        $this->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]),
            ['cara' => 'kurangi', 'stok_lama' => '8000', 'jumlah_kurang' => '0'])
            ->assertSessionHasErrors('jumlah_kurang');
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));

        $this->post(route('admin-utama.stok.ubah.simpan', [$this->beras, 'lokasi' => $this->pusat->id]),
            ['cara' => 'kurangi', 'stok_lama' => '8000', 'jumlah_kurang' => '2,5', 'keterangan' => 'Salah input kemarin'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $this->assertSame(5500.0, $this->saldo($this->pusat, $this->beras));
        $dokumen = PenyesuaianStok::sole();
        $this->assertEquals(-2500, (float) $dokumen->selisih);
        $this->assertSame('Salah input kemarin', $dokumen->keterangan);
    }
}
