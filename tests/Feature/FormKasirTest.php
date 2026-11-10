<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Form transaksi gaya kasir: dibuka sebagai popup, jumlah −/+, pilihan berupa kotak. */
class FormKasirTest extends TestCase
{
    use RefreshDatabase;

    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('public');

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
    }

    public function test_form_barang_masuk_siap_dibuka_sebagai_popup(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('pusat.barang-masuk'))
            ->assertSee('href="'.route('pusat.barang-masuk.catat').'" data-popup="panjang"', false);

        $this->app->forgetScopedInstances();

        $this->actingAs($this->adminPusat)
            ->get(route('pusat.barang-masuk.catat'))
            ->assertOk()
            ->assertSeeInOrder(['novalidate data-popup-isi', '<script type="application/json" id="data-bahan">', 'data-isian-jumlah', 'class="btn btn-merah"', 'Kirim'], false)
            ->assertDontSee('Jumlah dalam satuan beli');
    }

    public function test_form_barang_rusak_memakai_isian_alasan_dan_tetap_tersimpan(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.barang-keluar.catat'))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSeeInOrder(['<legend>', 'Alasan', 'name="baris[0][alasan]"'], false)
            ->assertDontSee('name="baris[0][sebab]"', false);

        \Illuminate\Support\Facades\DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 2000, 'diperbarui_pada' => now()]);

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.barang-keluar.simpan'), [
                'tanggal' => now()->toDateString(),
                'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '0,5', 'alasan' => 'Jatuh dari rak']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Jatuh dari rak', BarangKeluarDetail::sole()->penjelasan);
    }

    public function test_isian_alasan_diingat_setelah_validasi_gagal(): void
    {
        $this->actingAs($this->adminCabang)
            ->from(route('cabang.barang-keluar.catat'))
            ->post(route('cabang.barang-keluar.simpan'), [
                'tanggal' => now()->toDateString(),
                'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '', 'alasan' => 'Jatuh']],
            ])
            ->assertSessionHasErrors('baris.0.qty');

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.barang-keluar.catat'))
            ->assertSee('value="Jatuh"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_form_permintaan_tanpa_pilihan_prioritas_dan_isi_otomatis_di_popup(): void
    {
        \App\Models\BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 2000]);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.permintaan.buat'))
            ->assertOk()
            ->assertSee('data-form-info-stok data-popup-isi', false)
            ->assertDontSee('name="prioritas"', false)
            ->assertSee('href="'.route('cabang.permintaan.buat', ['usulan' => 1]).'" data-popup="panjang"', false);
    }

    public function test_tombol_cepat_dashboard_membuka_form_sebagai_popup(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.dashboard'))
            ->assertSee('href="'.route('cabang.barang-keluar.catat').'" class="tombol-cepat" data-popup="panjang"', false)
            ->assertSee('href="'.route('cabang.penjualan.catat').'"', false);
    }

    public function test_komponen_pilih_kotak(): void
    {
        $this->withViewErrors([]);

        $html = (string) $this->blade(
            '<x-form.pilih-kotak name="kondisi" label="Kondisi" :pilihan="[\'baik\' => \'Baik\', \'rusak\' => \'Rusak\']" value="rusak" wajib :kolom="4" />'
        );

        $this->assertStringContainsString('<legend>', $html);
        $this->assertStringContainsString('class="pilih-kotak kolom-4"', $html);
        $this->assertMatchesRegularExpression('/type="radio" name="kondisi" value="baik"\s+id="kondisi"\s+required/', $html);
        $this->assertStringContainsString('value="rusak" checked', $html);
    }
}
