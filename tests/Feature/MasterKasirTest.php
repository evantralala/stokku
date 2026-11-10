<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data master, stok minimum, pengaturan, dan laporan gaya kasir: popup, pilihan kotak, isian −/+, chip. */
class MasterKasirTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
    }

    private function lihat(string $rute, array $parameter = [])
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->adminUtama)->get(route($rute, $parameter))->assertOk();
    }

    public function test_tambah_dan_ubah_master_dibuka_sebagai_popup_sesuai_panjang_form(): void
    {
        $satuan = Satuan::create(['nama' => 'gram']);

        $this->lihat('admin-utama.satuan')
            ->assertSee('href="'.route('admin-utama.satuan.tambah').'" data-popup>', false)
            ->assertSee('href="'.route('admin-utama.satuan.ubah', $satuan).'" data-popup>', false);

        $lokasi = Cabang::create(['kode' => 'JB', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->lihat('admin-utama.lokasi')
            ->assertSee('href="'.route('admin-utama.lokasi.tambah').'" data-popup="panjang"', false);

        $this->lihat('admin-utama.lokasi.ubah', [$lokasi])
            ->assertSeeInOrder(['<div class="isi-master" data-popup-isi data-popup-ukuran="panjang">', 'Nama Lokasi', 'Status Lokasi', 'Nonaktifkan'], false);
    }

    public function test_saringan_status_berupa_chip_yang_mempertahankan_pencarian(): void
    {
        $this->lihat('admin-utama.satuan', ['cari' => 'gram'])
            ->assertSee('data-cari-utama', false)
            ->assertSee('href="'.e(route('admin-utama.satuan', ['cari' => 'gram', 'status' => 'nonaktif'])).'"', false)
            ->assertSee('aria-current="true">Aktif</a>', false);
    }

    public function test_form_lokasi_dan_pengguna_memakai_pilihan_kotak(): void
    {
        $this->lihat('admin-utama.lokasi.tambah')
            ->assertSee('type="radio" name="jenis" value="'.Cabang::JENIS_CABANG.'" checked', false)
            ->assertDontSee('name="sumber_penjualan"', false)
            ->assertDontSee('name="standar_stok_hari"', false);

        $this->lihat('admin-utama.pengguna.tambah')
            ->assertSee('class="pilih-kotak kolom-3"', false)
            ->assertSee('type="radio" name="role" value="'.User::ROLE_ADMIN_CABANG.'"', false);

        $cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.pengguna.simpan'), [
                'nama' => 'Rina', 'email' => 'rina@stokku.test', 'role' => User::ROLE_ADMIN_CABANG,
                'cabang_id' => $cabang->id, 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($cabang->id, User::where('email', 'rina@stokku.test')->sole()->cabang_id);
    }

    public function test_stok_minimum_dan_pengaturan_memakai_isian_jumlah(): void
    {
        Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $gram = Satuan::create(['nama' => 'gram']);
        BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id, 'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);

        $this->lihat('admin-utama.stok-minimum')
            ->assertSee('class="isian-jumlah ringkas" data-isian-jumlah', false)
            ->assertSee('name="minimum[', false);

        $this->seed(PengaturanSeeder::class);

        $this->lihat('admin-utama.pengaturan')
            ->assertSee('data-isian-jumlah data-langkah="1" data-min="1" data-maks="168"', false);
    }

    public function test_daftar_laporan_berupa_tombol_dan_periode_berupa_chip(): void
    {
        $this->lihat('admin-utama.laporan')
            ->assertSee('<nav class="tombol-cepat-grid laporan-grid" aria-label="Pilih laporan">', false)
            ->assertSee('href="'.route('admin-utama.laporan.rusak').'" class="tombol-cepat"', false);

        $this->lihat('admin-utama.laporan.rusak', ['dari' => now()->subDays(6)->toDateString(), 'sampai' => now()->toDateString()])
            ->assertSee('<nav class="chip-saring chip-geser saring-periode" aria-label="Periode cepat">', false)
            ->assertSee('aria-current="true">7 Hari</a>', false);
    }
}
