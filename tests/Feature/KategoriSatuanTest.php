<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KategoriSatuanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
    }

    public function test_kategori_baru_mendapat_urutan_berikutnya_di_form(): void
    {
        Kategori::create(['nama' => 'Sayur', 'urutan' => 4]);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.kategori.tambah'))
            ->assertOk()
            ->assertSee('value="5"', false);
    }

    public function test_kategori_urutan_tidak_boleh_negatif_dan_nama_unik(): void
    {
        Kategori::create(['nama' => 'Sayur', 'urutan' => 1]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.kategori.simpan'), ['nama' => 'Sayur', 'urutan' => -1])
            ->assertSessionHasErrors(['nama', 'urutan']);
    }

    public function test_satuan_dan_kategori_yang_dipakai_bahan_tidak_bisa_dihapus(): void
    {
        $gram = Satuan::create(['nama' => 'gram']);
        $kosong = Satuan::create(['nama' => 'liter']);
        $kategori = Kategori::create(['nama' => 'Bumbu']);

        BahanBaku::create([
            'nama' => 'Garam', 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);

        $this->actingAs($this->adminUtama)->delete(route('admin-utama.satuan.hapus', $gram))->assertSessionHas('galat');
        $this->actingAs($this->adminUtama)->delete(route('admin-utama.kategori.hapus', $kategori))->assertSessionHas('galat');
        $this->actingAs($this->adminUtama)->delete(route('admin-utama.satuan.hapus', $kosong))->assertSessionHas('sukses');

        $this->assertDatabaseHas('satuan', ['id' => $gram->id]);
        $this->assertDatabaseHas('kategori', ['id' => $kategori->id]);
        $this->assertDatabaseMissing('satuan', ['id' => $kosong->id]);
    }

    public function test_menu_data_master_muncul_di_sidebar(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertSeeInOrder(['<div class="nav-group">Data Master</div>', 'Bahan Baku', 'Kategori Bahan', 'Satuan', 'Lokasi'], false)
            ->assertDontSee('Supplier');
    }
}
