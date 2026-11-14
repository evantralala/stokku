<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\PenjualanHarian;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Satuan;
use App\Models\User;
use App\Services\AntreanPersetujuanService;
use App\Services\StokService;
use App\Support\AturanWajar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersetujuanWajarTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;
    private BahanBaku $mie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Kering']);
        $buat = fn (string $kode, string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = $buat('BB-0001', 'Nori');
        $this->mie = $buat('BB-0002', 'Mie ramen');

        // Cabang butuh nori: minimum 3 kg, stok 0,5 kg -> kebutuhan 2,5 kg (batas wajar 3 kg). Gudang Pusat punya 10 kg.
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 3000]);
        foreach ([[$this->cabang, $this->nori, 500], [$this->pusat, $this->nori, 10000], [$this->pusat, $this->mie, 10000]] as [$lokasi, $bahan, $saldo]) {
            DB::table('stok_saldo')->insert(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $bahan->id, 'saldo' => $saldo, 'diperbarui_pada' => now()]);
        }

        // Penjualan kemarin sudah dicatat supaya tidak ada peringatan dini "penjualan belum dicatat".
        PenjualanHarian::create(['cabang_id' => $this->cabang->id, 'tanggal' => now()->subDay()->toDateString(), 'dicatat_oleh' => $this->adminCabang->id]);
    }

    private function minta(array $baris): Permintaan
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => collect($baris)->map(fn ($qty, $id) => ['bahan_baku_id' => $id, 'qty' => $qty])->values()->all(),
        ])->assertSessionHasNoErrors();

        return Permintaan::latest('id')->first();
    }

    private function masuk(string $qty): BarangMasuk
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.barang-masuk.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();

        return BarangMasuk::latest('id')->first();
    }

    private function cek(string $jenis, int $id): array
    {
        return app(AntreanPersetujuanService::class)->daftar()->firstWhere('kunci', AturanWajar::kunci($jenis, $id))['cek'];
    }

    public function test_permintaan_dalam_kebutuhan_dan_stok_pusat_cukup_dianggap_wajar(): void
    {
        $wajar = $this->minta([$this->nori->id => '3']);
        $lebih = $this->minta([$this->mie->id => '5']);

        $this->assertSame(['wajar' => true, 'massal' => true, 'alasan' => null], $this->cek(Permintaan::DOKUMEN_TIPE, $wajar->id));
        $this->assertSame(['wajar' => false, 'massal' => true, 'alasan' => 'Mie ramen melebihi kebutuhan'], $this->cek(Permintaan::DOKUMEN_TIPE, $lebih->id));

        DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->where('bahan_baku_id', $this->nori->id)->update(['saldo' => 1000]);
        $this->assertSame('Stok pusat kurang: Nori', $this->cek(Permintaan::DOKUMEN_TIPE, $wajar->id)['alasan']);
    }

    public function test_barang_masuk_dibandingkan_dengan_barang_masuk_sebelumnya(): void
    {
        $pertama = $this->masuk('3');
        $this->assertSame('Nori baru pertama masuk', $this->cek(BarangMasuk::DOKUMEN_TIPE, $pertama->id)['alasan']);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $pertama), ['aksi' => 'setujui']);

        $biasa = $this->masuk('3,1');
        $banyak = $this->masuk('4');

        $this->assertTrue($this->cek(BarangMasuk::DOKUMEN_TIPE, $biasa->id)['wajar']);
        $this->assertSame('Nori lebih banyak dari biasa', $this->cek(BarangMasuk::DOKUMEN_TIPE, $banyak->id)['alasan']);
    }

    public function test_barang_rusak_dan_opname_selalu_dicek_satu_per_satu(): void
    {
        $rusak = BarangKeluar::create([
            'nomor' => 'BK-JTI-1', 'cabang_id' => $this->cabang->id, 'tanggal' => now()->toDateString(),
            'jenis' => BarangKeluar::JENIS_RUSAK, 'dicatat_oleh' => $this->adminCabang->id, 'status' => BarangKeluar::STATUS_MENUNGGU,
        ]);

        $this->assertSame(['wajar' => false, 'massal' => false, 'alasan' => 'Cek satu per satu'], $this->cek(BarangKeluar::DOKUMEN_TIPE, $rusak->id));
    }

    public function test_peringatan_dini_di_lokasi_membuat_dokumen_tidak_wajar(): void
    {
        $permintaan = $this->minta([$this->nori->id => '2']);
        DB::table('stok_saldo')->where('cabang_id', $this->cabang->id)->where('bahan_baku_id', $this->nori->id)->update(['saldo' => -100]);

        $this->assertSame('Ada peringatan di Cabang Jati', $this->cek(Permintaan::DOKUMEN_TIPE, $permintaan->id)['alasan']);
    }

    public function test_hanya_dokumen_wajar_yang_bisa_dicentang(): void
    {
        $wajar = $this->minta([$this->nori->id => '2']);
        $lebih = $this->minta([$this->mie->id => '5']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan'))
            ->assertOk()
            ->assertSee('Cek: Mie ramen melebihi kebutuhan')
            ->assertSee('Setujui yang Dicentang')
            ->assertSee('<span>Pilih Semua Wajar</span>', false)
            ->assertSee('data-pilih-semua="pilih-setujui"', false)
            ->assertSee('form="pilih-setujui" name="dokumen[]" value="permintaan:'.$wajar->id.'"', false)
            ->assertDontSee('value="permintaan:'.$lebih->id.'" data-pilih-baris', false);
    }

    public function test_setujui_yang_wajar_menyetujui_semua_barang_dan_melewati_yang_tidak_wajar(): void
    {
        $wajar = $this->minta([$this->nori->id => '2,5']);
        $lebih = $this->minta([$this->mie->id => '5']);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.wajar.setujui'), ['dokumen' => [
                AturanWajar::kunci(Permintaan::DOKUMEN_TIPE, $wajar->id),
                AturanWajar::kunci(Permintaan::DOKUMEN_TIPE, $lebih->id),
            ]])
            ->assertRedirect(route('admin-utama.persetujuan'))
            ->assertSessionHas('sukses', '1 dokumen disetujui. Perlu dicek: '.$lebih->nomor.' (Mie ramen melebihi kebutuhan).');

        $detail = $wajar->detail()->sole();
        $this->assertSame(PermintaanDetail::STATUS_DISETUJUI, $detail->status);
        $this->assertSame(2500.0, (float) $detail->qty_disetujui);
        $this->assertSame(PermintaanDetail::STATUS_MENUNGGU, $lebih->detail()->sole()->status);
    }

    public function test_setujui_satu_dokumen_wajar_dari_baris_antrean_menambah_stok(): void
    {
        $pertama = $this->masuk('3');
        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $pertama), ['aksi' => 'setujui']);
        $kedua = $this->masuk('2');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.wajar.setujui'), ['dokumen' => [AturanWajar::kunci(BarangMasuk::DOKUMEN_TIPE, $kedua->id)]])
            ->assertSessionHas('sukses', '1 dokumen disetujui.');

        $this->assertSame(BarangMasuk::STATUS_DISETUJUI, $kedua->fresh()->status);
        $this->assertSame(15000.0, app(StokService::class)->saldo($this->pusat->id, $this->nori->id));
    }

    public function test_jenis_yang_harus_dicek_satu_per_satu_ditolak_dan_hanya_admin_utama(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.wajar.setujui'), ['dokumen' => ['opname:1']])
            ->assertSessionHasErrors('dokumen.0');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.wajar.setujui'), ['dokumen' => []])
            ->assertSessionHasErrors('dokumen');

        $this->actingAs($this->adminPusat)->get(route('admin-utama.persetujuan'))->assertForbidden();
        $this->actingAs($this->adminPusat)
            ->post(route('admin-utama.persetujuan.wajar.setujui'), ['dokumen' => ['permintaan:1']])
            ->assertForbidden();
    }

    public function test_dashboard_admin_utama_menampilkan_antrean_dengan_aksi_cepat(): void
    {
        $wajar = $this->minta([$this->nori->id => '2']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Menunggu Keputusan', '1', '1 wajar'])
            ->assertSeeInOrder(['id="antrean-persetujuan"', $wajar->nomor, 'Wajar', 'value="permintaan:'.$wajar->id.'"', 'Setujui', 'data-pilih-baris', 'Setujui yang Dicentang'], false);
    }
}
