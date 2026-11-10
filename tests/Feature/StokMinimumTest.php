<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StokMinimumTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private BahanBaku $nori;
    private Cabang $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $kategori = Kategori::create(['nama' => 'Kering']);
        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);

        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        $this->pusat = $this->buatLokasi('Gudang Pusat', Cabang::JENIS_PUSAT);
    }

    private function buatLokasi(string $nama, string $jenis = Cabang::JENIS_CABANG): Cabang
    {
        return Cabang::create([
            'kode' => strtoupper(substr(md5($nama), 0, 4)), 'nama' => $nama,
            'jenis' => $jenis,
        ]);
    }

    public function test_nilai_satuan_beli_disimpan_dalam_satuan_dasar(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok-minimum.simpan'), ['lokasi_id' => $this->pusat->id, 'minimum' => [$this->nori->id => '2,5']])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $this->assertSame(2500.0, (float) BahanBakuMinimum::where('cabang_id', $this->pusat->id)->value('minimum'));

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok-minimum', ['lokasi' => $this->pusat->id]))
            ->assertSee('value="2,5"', false);
    }

    public function test_nilai_kosong_menghapus_batas_minimum(): void
    {
        BahanBakuMinimum::create(['bahan_baku_id' => $this->nori->id, 'cabang_id' => $this->pusat->id, 'minimum' => 1000]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok-minimum.simpan'), ['lokasi_id' => $this->pusat->id, 'minimum' => [$this->nori->id => '']]);

        $this->assertSame(0, BahanBakuMinimum::count());
    }

    public function test_nilai_negatif_ditolak_dengan_pesan_di_bawah_isian(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok-minimum.simpan'), ['lokasi_id' => $this->pusat->id, 'minimum' => [$this->nori->id => '-1']])
            ->assertSessionHasErrors('minimum.'.$this->nori->id);
    }

    public function test_lokasi_baru_otomatis_ada_di_pilihan(): void
    {
        $this->buatLokasi('Cabang Paling Baru');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok-minimum'))
            ->assertOk()
            ->assertSee('Cabang Paling Baru');
    }

    public function test_salin_dari_lokasi_lain_mengganti_semua_nilai(): void
    {
        $cabang = $this->buatLokasi('Cabang Baru');
        BahanBakuMinimum::create(['bahan_baku_id' => $this->nori->id, 'cabang_id' => $this->pusat->id, 'minimum' => 3000]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok-minimum.salin'), ['dari_lokasi_id' => $this->pusat->id, 'ke_lokasi_id' => $cabang->id])
            ->assertRedirect(route('admin-utama.stok-minimum', ['lokasi' => $cabang->id]));

        $this->assertSame(3000.0, (float) BahanBakuMinimum::where('cabang_id', $cabang->id)->value('minimum'));
    }

    public function test_bahan_nonaktif_tidak_tampil(): void
    {
        $this->nori->update(['aktif' => false]);
        $kategori = Kategori::first();
        BahanBaku::create([
            'nama' => 'Menma', 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $this->nori->satuan_dasar_id, 'satuan_beli_id' => $this->nori->satuan_beli_id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok-minimum'))
            ->assertSee('Menma')
            ->assertDontSee('Nori');
    }
}
