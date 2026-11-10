<?php

namespace Tests\Feature;

use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tombol CRUD berwarna jelas: Tambah merah menonjol, Ubah biru, Hapus merah tegas. */
class TombolCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_master_memakai_tombol_tambah_dan_tombol_ubah_biru(): void
    {
        $admin = User::factory()->adminUtama()->create();
        $satuan = Satuan::create(['nama' => 'gram']);

        $this->actingAs($admin)
            ->get(route('admin-utama.satuan'))
            ->assertOk()
            ->assertSee('class="btn btn-merah btn-tambah" href="'.route('admin-utama.satuan.tambah').'"', false)
            ->assertSee('tombol-ikon-aksi tombol-ikon-ubah" href="'.route('admin-utama.satuan.ubah', $satuan).'"', false);
    }

    public function test_data_yang_belum_dipakai_punya_tombol_hapus_merah(): void
    {
        $admin = User::factory()->adminUtama()->create();
        $satuan = Satuan::create(['nama' => 'ikat']);

        $this->actingAs($admin)
            ->get(route('admin-utama.satuan.ubah', $satuan))
            ->assertOk()
            ->assertSee('<button type="submit" class="btn btn-hapus">', false)
            ->assertDontSee('btn btn-bahaya">Hapus', false);
    }
}
