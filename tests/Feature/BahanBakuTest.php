<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BahanBakuTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Kategori $kategori;
    private Satuan $gram;
    private Satuan $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->kategori = Kategori::create(['nama' => 'Sayur']);
        $this->gram = Satuan::create(['nama' => 'gram']);
        $this->kg = Satuan::create(['nama' => 'kg']);
    }

    private function dataForm(array $ubah = []): array
    {
        return $ubah + [
            'nama'                => 'Daun bawang',
            'kategori_id'         => $this->kategori->id,
            'satuan_dasar_id'     => $this->gram->id,
            'satuan_beli_id'      => $this->kg->id,
            'isi_per_satuan_beli' => '1000',
            'toleransi_persen'    => '5',
        ];
    }

    private function buatBahan(array $data = []): BahanBaku
    {
        return BahanBaku::create($data + [
            'nama' => 'Bahan '.uniqid(),
            'kategori_id' => $this->kategori->id, 'satuan_dasar_id' => $this->gram->id,
            'satuan_beli_id' => $this->kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
    }

    public function test_bahan_baku_tidak_punya_kode(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.bahan-baku.tambah'))
            ->assertOk()
            ->assertDontSee('name="kode"', false);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.bahan-baku.simpan'), $this->dataForm(['kode' => 'BB-0001']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bahan_baku', ['nama' => 'Daun bawang']);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('bahan_baku', 'kode'));
    }

    public function test_angka_dengan_koma_diterima(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataForm(['isi_per_satuan_beli' => '1.250,5', 'toleransi_persen' => '2,5']))
            ->assertSessionHasNoErrors();

        $bahan = BahanBaku::firstWhere('nama', 'Daun bawang');
        $this->assertSame('1250.500', $bahan->isi_per_satuan_beli);
        $this->assertSame('2.50', $bahan->toleransi_persen);
    }

    public function test_isi_harus_satu_bila_satuan_beli_sama_dengan_satuan_dasar(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataForm(['satuan_beli_id' => $this->gram->id, 'isi_per_satuan_beli' => '1000']))
            ->assertSessionHasErrors('isi_per_satuan_beli');
    }

    public function test_isi_dan_toleransi_tidak_boleh_nol_atau_negatif(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataForm(['isi_per_satuan_beli' => '0', 'toleransi_persen' => '-1']))
            ->assertSessionHasErrors(['isi_per_satuan_beli', 'toleransi_persen']);
    }

    public function test_toleransi_bawaan_diambil_dari_pengaturan(): void
    {
        Pengaturan::create(['kunci' => 'toleransi_default', 'nilai' => '7']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.bahan-baku.tambah'))
            ->assertOk()
            ->assertSee('value="7"', false);
    }

    public function test_kategori_nonaktif_tidak_bisa_dipilih_untuk_bahan_baru(): void
    {
        $lama = Kategori::create(['nama' => 'Kategori Lama', 'aktif' => false]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataForm(['kategori_id' => $lama->id]))
            ->assertSessionHasErrors('kategori_id');
    }

    public function test_satuan_dasar_terkunci_bila_sudah_punya_stok(): void
    {
        $bahan = $this->buatBahan(['nama' => 'Daun bawang']);
        $lokasi = Cabang::create(['kode' => 'P1', 'nama' => 'Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        DB::table('stok_saldo')->insert([
            'cabang_id' => $lokasi->id, 'bahan_baku_id' => $bahan->id, 'saldo' => 500, 'diperbarui_pada' => now(),
        ]);

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.bahan-baku.perbarui', $bahan), $this->dataForm(['satuan_dasar_id' => $this->kg->id, 'isi_per_satuan_beli' => '1']))
            ->assertSessionHasErrors('satuan_dasar_id');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.bahan-baku.ubah', $bahan))
            ->assertSee('Sudah punya stok, hanya bisa diganti ke satuan sejenis (mis. kg ke gram).');
    }

    public function test_satu_satuan_kg_dan_liter_resepnya_gram_dan_ml(): void
    {
        $liter = Satuan::create(['nama' => 'liter']);
        $pcs = Satuan::create(['nama' => 'pcs']);
        $simpan = fn (string $nama, Satuan $satuan) => $this->actingAs($this->adminUtama)->post(route('admin-utama.bahan-baku.simpan'), [
            'nama' => $nama, 'kategori_id' => $this->kategori->id, 'satuan_id' => $satuan->id, 'toleransi_persen' => '5',
        ])->assertSessionHasNoErrors();

        $simpan('Beras', $this->kg);
        $simpan('Minyak', $liter);
        $simpan('Lemon', $pcs);

        $cek = fn (string $nama) => BahanBaku::where('nama', $nama)->with('satuanDasar', 'satuanBeli')->sole();
        $this->assertSame(['gram', 'kg', '1000.000'], [$cek('Beras')->satuanDasar->nama, $cek('Beras')->satuanBeli->nama, $cek('Beras')->isi_per_satuan_beli]);
        $this->assertSame(['ml', 'liter'], [$cek('Minyak')->satuanDasar->nama, $cek('Minyak')->satuanBeli->nama]);
        $this->assertSame(['pcs', 'pcs', '1.000'], [$cek('Lemon')->satuanDasar->nama, $cek('Lemon')->satuanBeli->nama, $cek('Lemon')->isi_per_satuan_beli]);
        $this->assertTrue(Satuan::where('nama', 'ml')->exists());

        $beras = $cek('Beras');
        $lokasi = Cabang::create(['kode' => 'P1', 'nama' => 'Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        DB::table('stok_saldo')->insert(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $beras->id, 'saldo' => 500, 'diperbarui_pada' => now()]);
        $ubah = fn (Satuan $satuan) => $this->actingAs($this->adminUtama)->put(route('admin-utama.bahan-baku.perbarui', $beras), [
            'nama' => 'Beras', 'kategori_id' => $this->kategori->id, 'satuan_id' => $satuan->id, 'toleransi_persen' => '5',
        ]);

        $ubah($pcs)->assertSessionHasErrors('satuan_id');
        $ubah($this->gram)->assertSessionHasNoErrors();
        $this->assertSame(['gram', 'gram', '1.000'], [$cek('Beras')->satuanDasar->nama, $cek('Beras')->satuanBeli->nama, $cek('Beras')->isi_per_satuan_beli]);
    }

    public function test_hapus_bahan_belum_dipakai_ikut_menghapus_stok_minimum(): void
    {
        $bahan = $this->buatBahan();
        $lokasi = Cabang::create(['kode' => 'C1', 'nama' => 'Cabang', 'jenis' => Cabang::JENIS_CABANG]);
        BahanBakuMinimum::create(['bahan_baku_id' => $bahan->id, 'cabang_id' => $lokasi->id, 'minimum' => 1000]);

        $this->actingAs($this->adminUtama)
            ->delete(route('admin-utama.bahan-baku.hapus', $bahan))
            ->assertSessionHas('sukses');

        $this->assertDatabaseMissing('bahan_baku', ['id' => $bahan->id]);
        $this->assertDatabaseMissing('bahan_baku_minimum', ['bahan_baku_id' => $bahan->id]);
    }

    public function test_daftar_bisa_difilter_per_kategori(): void
    {
        $daging = Kategori::create(['nama' => 'Daging']);
        $this->buatBahan(['nama' => 'Kubis Segar']);
        $this->buatBahan(['nama' => 'Ayam Fillet', 'kategori_id' => $daging->id]);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.bahan-baku', ['kategori' => $daging->id]))
            ->assertSee('Ayam Fillet')
            ->assertDontSee('Kubis Segar');
    }
}
