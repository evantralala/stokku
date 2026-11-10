<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman galat bergaya aplikasi (bukan halaman bawaan Laravel). */
class HalamanGalatTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_tidak_ditemukan_memakai_tampilan_aplikasi(): void
    {
        $this->get('/halaman-yang-tidak-ada')
            ->assertNotFound()
            ->assertSee('<main class="galat-halaman">', false)
            ->assertSee('Halaman Tidak Ditemukan')
            ->assertSee('Kembali ke beranda');
    }

    public function test_akses_ditolak_menampilkan_pesan_dari_sistem(): void
    {
        $gudang = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $pusat = User::factory()->adminPusat($gudang->id)->create();

        $this->actingAs($pusat)
            ->get(route('admin-utama.dashboard'))
            ->assertForbidden()
            ->assertSee('Akses Ditolak')
            ->assertSee('<p class="galat-kode">403</p>', false);
    }
}
