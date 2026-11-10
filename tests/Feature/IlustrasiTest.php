<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IlustrasiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
    }

    public function test_halaman_kosong_memakai_gambar_dan_teks_tetap_terbaca(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan'))
            ->assertOk()
            ->assertSeeInOrder(['kosong-gambar', 'aria-hidden="true"', 'class="kosong-ilustrasi"', 'Semua sudah diputuskan.'], false);

        $this->get(route('notifikasi'))
            ->assertOk()
            ->assertSeeInOrder(['kosong-gambar', 'il-latar', 'Belum ada notifikasi.'], false);

        $this->get(route('admin-utama.satuan'))
            ->assertOk()
            ->assertSeeInOrder(['kosong-gambar', 'Belum ada satuan.', '+ Tambah Satuan'], false);

        $this->get(route('admin-utama.satuan', ['cari' => 'tidak-ada', 'status' => 'semua']))
            ->assertOk()
            ->assertSee('Belum ada satuan.');
    }

    public function test_kartu_dashboard_dan_judul_panel_berikon(): void
    {
        Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['class="kartu-ikon"', 'Menunggu Keputusan'], false)
            ->assertSeeInOrder(['class="ikon-judul"', 'Antrean Persetujuan'], false);

        $this->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSeeInOrder(['id="judul-umum"><span class="ikon-judul"', 'id="judul-modul"><span class="ikon-judul"'], false);
    }
}
