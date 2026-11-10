<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TampilanTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_memuat_laci_menu_dan_navigasi_bawah(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertDontSee('class="crumb"', false)
            ->assertSee('id="sidebar"', false)
            ->assertSee('aria-label="Menu cepat"', false)
            ->assertSee('data-buka-menu', false)
            ->assertSee('id="dialog-konfirmasi"', false);
    }

    public function test_pesan_sukses_tampil_setelah_menyimpan(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)
            ->followingRedirects()
            ->post(route('admin-utama.satuan.simpan'), ['nama' => 'liter'])
            ->assertOk()
            ->assertSee('toast-sukses', false)
            ->assertSee('Satuan liter berhasil ditambahkan.');
    }

    public function test_validasi_gagal_menampilkan_pengingat_dan_pesan_di_bawah_isian(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)
            ->from(route('admin-utama.satuan.tambah'))
            ->followingRedirects()
            ->post(route('admin-utama.satuan.simpan'), ['nama' => ''])
            ->assertOk()
            ->assertSee('Ada isian yang belum benar')
            ->assertSee('Nama satuan wajib diisi.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_navigasi_bawah_memakai_label_pendek_dan_menu_utama_tanpa_judul_grup(): void
    {
        $cabang = Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);
        $admin = User::factory()->adminCabang($cabang->id)->create();

        $this->actingAs($admin)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSee('<span>Beranda</span>', false)
            ->assertSee('<span>Terima</span>', false)
            ->assertSee('<span>Stok</span>', false)
            ->assertDontSee('<div class="nav-group">Pantauan</div>', false)
            ->assertSee('<div class="nav-group">Akun</div>', false);
    }

    public function test_tautan_kembali_di_kepala_halaman(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();

        $this->actingAs($adminUtama)
            ->get(route('admin-utama.satuan.tambah'))
            ->assertOk()
            ->assertSeeInOrder(['<h1>Tambah Satuan</h1>', '<header class="topbar">', '<div class="crumb">', '<a class="link" href="'.route('admin-utama.satuan').'">Satuan</a>'], false);
    }

    public function test_daftar_master_ringkas_dengan_nama_sebagai_tautan_ubah(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();
        $aktif = Kategori::create(['nama' => 'Sayur']);
        $pasif = Kategori::create(['nama' => 'Buah', 'aktif' => false]);

        $this->actingAs($adminUtama)
            ->get(route('admin-utama.kategori', ['status' => 'semua']))
            ->assertOk()
            ->assertSee('tabel-kartu tabel-ringkas', false)
            ->assertSee('<a class="nama-barang tautan-baris" href="'.route('admin-utama.kategori.ubah', $aktif).'" data-popup>Sayur</a>', false)
            ->assertSee(route('admin-utama.kategori.ubah', $pasif), false)
            ->assertSee('Tidak Aktif')
            ->assertSee('<nav class="chip-saring chip-geser" aria-label="Saring status">', false)
            ->assertSee('href="'.route('admin-utama.kategori', ['status' => 'aktif']).'"', false)
            ->assertSee('aria-current="true">Semua</a>', false)
            ->assertSeeInOrder(['<div class="kartu-grid kartu-grid-master">', 'Total Kategori Bahan', 'Status Aktif', 'Tidak Aktif'], false)
            ->assertSeeInOrder(['<div class="alat alat-master">', 'aria-label="Hapus saringan"', '<div class="alat-master-aksi">', route('admin-utama.kategori.tambah')], false)
            ->assertSee('title="Ubah" aria-label="Ubah Sayur"', false)
            ->assertSee('<th class="kolom-no">No</th>', false);
    }

    public function test_form_tambah_tanpa_sub_judul_tidak_menampilkan_baris_kosong(): void
    {
        $adminUtama = User::factory()->adminUtama()->create();

        foreach (['admin-utama.lokasi.tambah', 'admin-utama.menu.tambah', 'admin-utama.satuan.tambah', 'admin-utama.pengguna.tambah'] as $rute) {
            $this->actingAs($adminUtama)
                ->get(route($rute))
                ->assertOk()
                ->assertDontSee('<div class="tgl"></div>', false);
        }
    }

    public function test_isian_jumlah_menampilkan_koma_dan_tombol_disiapkan_untuk_javascript(): void
    {
        $this->withViewErrors(['jumlah' => 'Jumlah wajib diisi.']);

        $html = (string) $this->blade(
            '<x-isian-jumlah name="jumlah" label="Jumlah" value="2.5" satuan="kg" langkah="0.5" maks="10" desimal />'
        );

        $this->assertStringContainsString('class="isian-jumlah" data-isian-jumlah', $html);
        $this->assertStringContainsString('data-langkah="0.5" data-min="0" data-maks="10"', $html);
        $this->assertStringContainsString('aria-label="Kurangi Jumlah" aria-controls="jumlah" hidden>', $html);
        $this->assertStringContainsString('aria-label="Tambah Jumlah" aria-controls="jumlah" hidden>', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('value="2,5"', $html);
        $this->assertStringContainsString('<span class="isian-jumlah-satuan" aria-hidden="true">kg</span>', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('Jumlah wajib diisi.', $html);
    }
}
