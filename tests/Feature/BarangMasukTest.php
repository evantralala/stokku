<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangMasuk;
use App\Models\BarangMasukDetail;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BarangMasukTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private User $admin;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->admin = User::factory()->adminPusat($this->pusat->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
    }

    private function data(array $ubah = []): array
    {
        return $ubah + [
            'tanggal' => now()->toDateString(),
            'catatan' => 'Nota 123',
            'baris'   => [
                ['bahan_baku_id' => $this->nori->id, 'qty' => '2,5', 'keterangan' => ''],
                ['bahan_baku_id' => '', 'qty' => '', 'keterangan' => ''],
            ],
        ];
    }

    public function test_catat_membuat_dokumen_menunggu_dan_mutasi_tanpa_mengubah_stok(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pusat.barang-masuk.simpan'), $this->data())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $dokumen = BarangMasuk::first();
        $this->assertSame('BM-GP-'.now()->format('ymd').'-001', $dokumen->nomor);
        $this->assertSame(BarangMasuk::STATUS_MENUNGGU, $dokumen->status);
        $this->assertSame($this->pusat->id, $dokumen->cabang_id);

        $detail = $dokumen->detail()->first();
        $this->assertSame('2500.000', $detail->qty);

        $mutasi = StokMutasi::first();
        $this->assertSame(StokMutasi::STATUS_MENUNGGU, $mutasi->status);
        $this->assertSame(StokMutasi::JENIS_MASUK_SUPPLIER, $mutasi->jenis);
        $this->assertSame(0.0, app(StokService::class)->saldo($this->pusat->id, $this->nori->id));
    }

    public function test_nomor_dokumen_berurutan(): void
    {
        $this->actingAs($this->admin)->post(route('pusat.barang-masuk.simpan'), $this->data());
        $this->actingAs($this->admin)->post(route('pusat.barang-masuk.simpan'), $this->data());

        $this->assertSame(
            ['BM-GP-'.now()->format('ymd').'-001', 'BM-GP-'.now()->format('ymd').'-002'],
            BarangMasuk::orderBy('id')->pluck('nomor')->all()
        );
    }

    public function test_validasi_tanggal_dan_jumlah(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pusat.barang-masuk.simpan'), $this->data([
                'tanggal' => now()->addDay()->toDateString(),
                'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '0']],
            ]))
            ->assertSessionHasErrors(['tanggal', 'baris.0.qty']);

        $this->actingAs($this->admin)
            ->post(route('pusat.barang-masuk.simpan'), $this->data(['baris' => []]))
            ->assertSessionHasErrors('baris');

        $this->assertSame(0, BarangMasuk::count());
    }

    public function test_ubah_membatalkan_mutasi_lama_dan_menyimpan_baris_lama_sebagai_riwayat(): void
    {
        $this->actingAs($this->admin)->post(route('pusat.barang-masuk.simpan'), $this->data());
        $dokumen = BarangMasuk::first();

        $this->actingAs($this->admin)
            ->put(route('pusat.barang-masuk.perbarui', $dokumen), $this->data([
                'baris' => [['bahan_baku_id' => $this->nori->id, 'qty' => '3']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $dokumen->detail()->count());
        $this->assertSame(1, BarangMasukDetail::onlyTrashed()->count());
        $this->assertSame(['batal', 'menunggu'], StokMutasi::orderBy('id')->pluck('status')->all());
        $this->assertSame('3000.000', StokMutasi::menunggu()->first()->jumlah);
    }

    public function test_batalkan_dan_dokumen_disetujui_tidak_bisa_diubah(): void
    {
        $this->actingAs($this->admin)->post(route('pusat.barang-masuk.simpan'), $this->data());
        $dokumen = BarangMasuk::first();

        $this->actingAs($this->admin)
            ->patch(route('pusat.barang-masuk.batal', $dokumen))
            ->assertRedirect(route('pusat.barang-masuk'));

        $this->assertSame(BarangMasuk::STATUS_BATAL, $dokumen->fresh()->status);
        $this->assertSame(StokMutasi::STATUS_BATAL, StokMutasi::first()->status);

        $dokumen->update(['status' => BarangMasuk::STATUS_DISETUJUI]);

        $this->actingAs($this->admin)
            ->get(route('pusat.barang-masuk.ubah', $dokumen))
            ->assertRedirect(route('pusat.barang-masuk.lihat', $dokumen))
            ->assertSessionHas('galat');
    }

    public function test_tabel_supplier_dan_kolom_supplier_barang_masuk_sudah_dihapus(): void
    {
        $this->assertFalse(Schema::hasTable('supplier'));
        $this->assertFalse(Schema::hasColumn('barang_masuk_detail', 'supplier_id'));
    }

    public function test_form_berisi_data_bahan_tanpa_pilihan_supplier(): void
    {
        $this->actingAs($this->admin)
            ->get(route('pusat.barang-masuk.catat'))
            ->assertOk()
            ->assertSee('"satuan":"kg"', false)
            ->assertDontSee('supplier_id', false);
    }

    public function test_hanya_admin_pusat_yang_terhubung_ke_gudang_pusat(): void
    {
        $cabang = Cabang::create(['kode' => 'C1', 'nama' => 'Cabang', 'jenis' => Cabang::JENIS_CABANG]);
        $adminCabang = User::factory()->adminCabang($cabang->id)->create();
        $adminPusatSalahLokasi = User::factory()->adminPusat($cabang->id)->create();

        $this->actingAs($adminCabang)->get(route('pusat.barang-masuk'))->assertForbidden();
        $this->actingAs($adminPusatSalahLokasi)->get(route('pusat.barang-masuk'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('pusat.barang-masuk'))->assertOk();
    }

    public function test_detail_menampilkan_jumlah_dalam_satuan_beli_dan_dasar(): void
    {
        $this->actingAs($this->admin)->post(route('pusat.barang-masuk.simpan'), $this->data());

        $this->actingAs($this->admin)
            ->get(route('pusat.barang-masuk.lihat', BarangMasuk::first()))
            ->assertOk()
            ->assertSee('2,5 kg')
            ->assertSee('2.500 gram')
            ->assertSee('Menunggu Persetujuan');
    }

    public function test_halaman_stok_menampilkan_barang_masuk_yang_menunggu_persetujuan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pusat.barang-masuk.simpan'), $this->data())
            ->assertSessionHas('sukses', 'Barang masuk '.BarangMasuk::first()->nomor.' tersimpan. Stok bertambah setelah disetujui Admin Utama.');

        $this->get(route('pusat.stok'))
            ->assertOk()
            ->assertSee('+2,5 kg menunggu')
            ->assertHeader('Cache-Control', 'no-store, private');

        $adminUtama = User::factory()->adminUtama()->create();
        $this->actingAs($adminUtama)
            ->post(route('admin-utama.persetujuan.barang-masuk.putuskan', BarangMasuk::first()), ['aksi' => 'setujui'])
            ->assertSessionHas('sukses');

        $this->actingAs($this->admin)
            ->get(route('pusat.stok'))
            ->assertOk()
            ->assertSee('2,5 kg')
            ->assertDontSee('menunggu</div>', false);
    }
}
