<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Persetujuan;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersetujuanTest extends TestCase
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
    }

    private function buatPermintaan(): Permintaan
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => [
                ['bahan_baku_id' => $this->nori->id, 'qty' => '2'],
                ['bahan_baku_id' => $this->mie->id, 'qty' => '5'],
            ],
        ]);

        return Permintaan::latest('id')->first();
    }

    private function buatBarangMasuk(): BarangMasuk
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.barang-masuk.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '3']],
        ]);

        return BarangMasuk::latest('id')->first();
    }

    private function detail(Permintaan $p, BahanBaku $b): PermintaanDetail
    {
        return $p->detail()->where('bahan_baku_id', $b->id)->first();
    }

    public function test_antrean_menampilkan_permintaan_dan_barang_masuk_beserta_jumlah_di_menu(): void
    {
        $permintaan = $this->buatPermintaan();
        $masuk = $this->buatBarangMasuk();

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan'))
            ->assertOk()
            ->assertSee($permintaan->nomor)
            ->assertSee($masuk->nomor)
            ->assertSee('<span class="pill">2</span>', false);
    }

    public function test_putuskan_permintaan_per_barang_dengan_jumlah_diubah(): void
    {
        $permintaan = $this->buatPermintaan();
        $nori = $this->detail($permintaan, $this->nori);
        $mie = $this->detail($permintaan, $this->mie);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), ['keputusan' => [
                $nori->id => ['aksi' => 'setujui', 'qty' => '1,5', 'catatan' => 'Kirim separuh dulu'],
                $mie->id  => ['aksi' => 'tolak', 'qty' => '', 'catatan' => 'Stok cabang masih cukup'],
            ]])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin-utama.kiriman.buat', $permintaan));

        $this->assertSame(PermintaanDetail::STATUS_DISETUJUI, $nori->fresh()->status);
        $this->assertSame('1500.000', $nori->fresh()->qty_disetujui);
        $this->assertSame(PermintaanDetail::STATUS_DITOLAK, $mie->fresh()->status);
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $permintaan->fresh()->status);

        $this->assertSame(2, Persetujuan::where('dokumen_tipe', Permintaan::DOKUMEN_TIPE)->count());
        $this->assertSame($this->adminUtama->id, Persetujuan::first()->oleh_user_id);
    }

    public function test_barang_nanti_tetap_menunggu_dan_semua_ditolak_menutup_permintaan(): void
    {
        $permintaan = $this->buatPermintaan();
        $nori = $this->detail($permintaan, $this->nori);
        $mie = $this->detail($permintaan, $this->mie);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), ['keputusan' => [
            $nori->id => ['aksi' => 'tolak'],
            $mie->id  => ['aksi' => 'nanti'],
        ]]);

        $this->assertSame(PermintaanDetail::STATUS_MENUNGGU, $mie->fresh()->status);
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $permintaan->fresh()->status);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), ['keputusan' => [
            $mie->id => ['aksi' => 'tolak'],
        ]]);

        $this->assertSame(Permintaan::STATUS_SELESAI, $permintaan->fresh()->status);
    }

    public function test_jumlah_wajib_lebih_dari_nol_saat_disetujui(): void
    {
        $permintaan = $this->buatPermintaan();
        $nori = $this->detail($permintaan, $this->nori);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), ['keputusan' => [
                $nori->id => ['aksi' => 'setujui', 'qty' => '0'],
            ]])
            ->assertSessionHasErrors('keputusan.'.$nori->id.'.qty');
    }

    public function test_lewat_batas_tidak_bisa_diputuskan_dan_langsung_ditolak_otomatis(): void
    {
        $permintaan = $this->buatPermintaan();
        $nori = $this->detail($permintaan, $this->nori);

        $this->travel(25)->hours();

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), ['keputusan' => [
                $nori->id => ['aksi' => 'setujui', 'qty' => '2'],
            ]])
            ->assertSessionHas('galat');

        $this->assertSame(PermintaanDetail::STATUS_OTOMATIS_DITOLAK, $nori->fresh()->status);
        $this->assertSame(Permintaan::STATUS_SELESAI, $permintaan->fresh()->status);
    }

    public function test_setujui_barang_masuk_menambah_stok_gudang_pusat(): void
    {
        $masuk = $this->buatBarangMasuk();

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $masuk), ['aksi' => 'setujui'])
            ->assertSessionHas('sukses');

        $this->assertSame(BarangMasuk::STATUS_DISETUJUI, $masuk->fresh()->status);
        $this->assertSame(StokMutasi::STATUS_SAH, StokMutasi::first()->status);
        $this->assertSame(3000.0, app(StokService::class)->saldo($this->pusat->id, $this->nori->id));

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $masuk), ['aksi' => 'setujui'])
            ->assertSessionHas('galat');
        $this->assertSame(3000.0, app(StokService::class)->saldo($this->pusat->id, $this->nori->id));
    }

    public function test_kembalikan_barang_masuk_wajib_alasan_lalu_pencatat_bisa_memperbaiki(): void
    {
        $masuk = $this->buatBarangMasuk();

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $masuk), ['aksi' => 'revisi', 'catatan' => ''])
            ->assertSessionHasErrors('catatan');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $masuk), ['aksi' => 'revisi', 'catatan' => 'Jumlah seharusnya 2 kg']);

        $this->assertSame(BarangMasuk::STATUS_REVISI, $masuk->fresh()->status);
        $this->assertSame(StokMutasi::STATUS_BATAL, StokMutasi::first()->status);
        $this->assertSame(0.0, app(StokService::class)->saldo($this->pusat->id, $this->nori->id));

        $this->actingAs($this->adminPusat)
            ->get(route('pusat.barang-masuk.lihat', $masuk))
            ->assertSee('Diminta Perbaikan')
            ->assertSee('Jumlah seharusnya 2 kg');
    }

    public function test_tolak_barang_masuk_membatalkan_dokumen(): void
    {
        $masuk = $this->buatBarangMasuk();

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $masuk), ['aksi' => 'tolak', 'catatan' => 'Barang tidak pernah datang']);

        $this->assertSame(BarangMasuk::STATUS_BATAL, $masuk->fresh()->status);
        $this->assertSame(0.0, app(StokService::class)->saldo($this->pusat->id, $this->nori->id));
    }

    public function test_halaman_keputusan_menampilkan_stok_pusat_dan_hanya_untuk_admin_utama(): void
    {
        $permintaan = $this->buatPermintaan();

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan.permintaan', $permintaan))
            ->assertOk()
            ->assertSee('Stok Gudang Pusat')
            ->assertSee('Simpan Keputusan');

        $this->actingAs($this->adminPusat)->get(route('admin-utama.persetujuan'))->assertForbidden();
    }

    public function test_barang_yang_sudah_diputuskan_tampil_ringkas_dengan_catatan(): void
    {
        $permintaan = $this->buatPermintaan();
        $nori = $this->detail($permintaan, $this->nori);
        $mie = $this->detail($permintaan, $this->mie);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), ['keputusan' => [
            $nori->id => ['aksi' => 'tolak', 'catatan' => 'Stok gudang sedang kosong'],
            $mie->id  => ['aksi' => 'nanti'],
        ]]);

        $this->get(route('admin-utama.persetujuan.permintaan', $permintaan))
            ->assertOk()
            ->assertSeeInOrder(['Mie ramen', 'Diminta', '5 kg', 'Setujui', 'Sudah Diputuskan', 'Nori', 'Stok gudang sedang kosong', '2 kg', 'Ditolak']);
    }
}
