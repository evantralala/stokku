<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KirimanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;
    private Permintaan $permintaan;
    private PermintaanDetail $detail;

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
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 10000, 'diperbarui_pada' => now()]);

        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => [['bahan_baku_id' => $this->nori->id, 'qty' => '4']],
        ]);
        $this->permintaan = Permintaan::first();
        $this->detail = $this->permintaan->detail()->first();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $this->permintaan), [
            'keputusan' => [$this->detail->id => ['aksi' => 'setujui', 'qty' => '4']],
        ]);
    }

    /** Kirim permintaan; $stokGudang (kg) mengatur stok gudang lebih dulu, karena jumlah kiriman mengikuti persetujuan dan stok yang ada. */
    private function kirim(?string $stokGudang = null)
    {
        if ($stokGudang !== null) {
            DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->where('bahan_baku_id', $this->nori->id)
                ->update(['saldo' => (float) str_replace(',', '.', $stokGudang) * 1000]);
        }

        return $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.simpan', $this->permintaan));
    }

    private function saldo(Cabang $lokasi): float
    {
        return app(StokService::class)->saldo($lokasi->id, $this->nori->id);
    }

    public function test_permintaan_disetujui_muncul_siap_dikirim_dengan_jumlah_terisi(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('pusat.kiriman'))
            ->assertOk()
            ->assertSee($this->permintaan->nomor)
            ->assertSee('<span class="pill">1</span>', false);

        $this->actingAs($this->adminPusat)
            ->get(route('pusat.kiriman.buat', $this->permintaan))
            ->assertOk()
            ->assertSeeInOrder(['Nori', '4 kg', '10 kg', '4 kg'])
            ->assertSee('Kirim Sekarang')
            ->assertDontSee('name="jumlah', false);
    }

    public function test_kirim_mengurangi_stok_pusat_dan_mencatat_jumlah_terkirim(): void
    {
        $this->kirim()->assertSessionHasNoErrors()->assertSessionHas('sukses');

        $kiriman = Kiriman::first();
        $this->assertSame('KR-GP-'.now()->format('ymd').'-001', $kiriman->nomor);
        $this->assertSame(Kiriman::STATUS_DIKIRIM, $kiriman->status);
        $this->assertSame($this->cabang->id, $kiriman->ke_cabang_id);

        $this->assertSame(6000.0, $this->saldo($this->pusat));
        $this->assertSame(0.0, $this->saldo($this->cabang));
        $this->assertSame(StokMutasi::STATUS_SAH, StokMutasi::where('jenis', StokMutasi::JENIS_KIRIM_KELUAR)->value('status'));

        $this->assertSame('4000.000', $this->detail->fresh()->qty_dikirim);
        $this->assertSame(Permintaan::STATUS_SELESAI, $this->permintaan->fresh()->status);
    }

    public function test_kirim_sebagian_lalu_sisanya(): void
    {
        $this->kirim('1,5');
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $this->permintaan->fresh()->status);
        $this->assertSame(2500.0, $this->detail->fresh()->utang_kiriman);

        $this->kirim('2,5');
        $this->assertSame(Permintaan::STATUS_SELESAI, $this->permintaan->fresh()->status);
        $this->assertSame(2, Kiriman::count());
    }

    public function test_jumlah_tidak_bisa_diketik_dan_stok_kosong_ditolak(): void
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.simpan', $this->permintaan), [
            'jumlah' => [$this->detail->id => '9'],
        ])->assertSessionHas('sukses');
        $this->assertSame('4000.000', Kiriman::sole()->detail()->value('qty_kirim'));

        $lain = Permintaan::create([
            'nomor' => 'PM-JTI-UJI', 'cabang_id' => $this->cabang->id, 'diajukan_pada' => now(), 'status' => Permintaan::STATUS_MENUNGGU,
        ]);
        $lain->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty_diminta' => 2000, 'qty_disetujui' => 2000, 'status' => PermintaanDetail::STATUS_DISETUJUI]);
        DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->update(['saldo' => 0]);

        $this->get(route('pusat.kiriman.buat', $lain))->assertOk()->assertSee('Belum bisa')->assertSee('Stok Kurang')->assertDontSee('Kirim Sekarang');
        $this->post(route('pusat.kiriman.simpan', $lain))->assertSessionHas('galat', 'Stok Gudang Pusat kosong untuk semua barang permintaan ini. Isi stok dulu lewat Barang Masuk.');
        $this->assertSame('0.000', $lain->detail()->value('qty_dikirim'));
    }

    public function test_cabang_melihat_jumlah_kiriman_untuk_dicocokkan(): void
    {
        $this->kirim('3,5');
        $kiriman = Kiriman::first();

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang'))
            ->assertSee($kiriman->nomor)
            ->assertSee('<span class="pill">1</span>', false);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang.lihat', $kiriman))
            ->assertOk()
            ->assertSee('Nori')
            ->assertSeeInOrder(['Nori', '3,5 kg', 'Sesuai Semua', 'Ada yang Beda'])
            ->assertSee('type="radio" name="kesesuaian" value="sesuai" checked', false);
    }

    public function test_terima_sesuai_menambah_stok_cabang_dan_selesai(): void
    {
        $this->kirim();
        $kiriman = Kiriman::first();
        $kd = $kiriman->detail()->first();

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
                $kd->id => ['qty' => '4', 'kondisi' => KirimanDetail::KONDISI_BAIK, 'catatan' => ''],
            ]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $this->assertSame(Kiriman::STATUS_SELESAI, $kiriman->fresh()->status);
        $this->assertSame(4000.0, $this->saldo($this->cabang));
        $this->assertSame(StokMutasi::STATUS_SAH, StokMutasi::where('jenis', StokMutasi::JENIS_KIRIM_MASUK)->value('status'));
        $this->assertSame($this->adminCabang->id, $kd->fresh()->dichecklist_oleh);
    }

    public function test_selisih_di_luar_toleransi_ditandai_dan_terlihat_setelah_dihitung(): void
    {
        $this->kirim();
        $kiriman = Kiriman::first();
        $kd = $kiriman->detail()->first();

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
                $kd->id => ['qty' => '3', 'kondisi' => KirimanDetail::KONDISI_RUSAK, 'catatan' => '1 kg basah'],
            ]]);

        $this->assertSame(Kiriman::STATUS_DICHECKLIST, $kiriman->fresh()->status);
        $this->assertSame(3000.0, $this->saldo($this->cabang));
        $this->assertSame(-1000.0, $kd->fresh()->selisih);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang.lihat', $kiriman))
            ->assertSee('Diterima, Ada Selisih')
            ->assertSee('-1 kg');
    }

    public function test_validasi_terima_dan_tidak_bisa_dua_kali(): void
    {
        $this->kirim();
        $kiriman = Kiriman::first();
        $kd = $kiriman->detail()->first();

        $this->actingAs($this->adminCabang)
            ->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
                $kd->id => ['qty' => '', 'kondisi' => KirimanDetail::KONDISI_RUSAK, 'catatan' => ''],
            ]])
            ->assertSessionHasErrors(['terima.'.$kd->id.'.qty', 'terima.'.$kd->id.'.catatan']);

        $this->actingAs($this->adminCabang)->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
            $kd->id => ['qty' => '', 'kondisi' => KirimanDetail::KONDISI_TIDAK_ADA, 'catatan' => 'Tidak ikut di mobil'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('0.000', $kd->fresh()->qty_terima);
        $this->assertSame(0.0, $this->saldo($this->cabang));

        $this->actingAs($this->adminCabang)->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
            $kd->id => ['qty' => '4', 'kondisi' => KirimanDetail::KONDISI_BAIK],
        ]])->assertSessionHas('galat');
        $this->assertSame(0.0, $this->saldo($this->cabang));
    }

    public function test_cabang_lain_tidak_bisa_membuka_kiriman(): void
    {
        $this->kirim();
        $lain = Cabang::create(['kode' => 'PWS', 'nama' => 'Lain', 'jenis' => Cabang::JENIS_CABANG]);

        $this->actingAs(User::factory()->adminCabang($lain->id)->create())
            ->get(route('cabang.terima-barang.lihat', Kiriman::first()))
            ->assertNotFound();
    }

    public function test_daftar_dan_form_kirim_tampil_ringkas(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('pusat.kiriman.buat', $this->permintaan))
            ->assertOk()
            ->assertSee('Siap Dikirim')
            ->assertDontSee('menyusul');

        $this->kirim('3')->assertSessionHas('sukses', fn (string $p) => str_contains($p, '1 barang belum terkirim penuh'));
        $kiriman = Kiriman::first();

        $this->get(route('pusat.kiriman'))
            ->assertOk()
            ->assertSee(route('pusat.kiriman.lihat', $kiriman), false)
            ->assertSee('1 barang')
            ->assertDontSee('>Lihat<', false);

        DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->update(['saldo' => 500]);
        $this->get(route('pusat.kiriman.buat', $this->permintaan))
            ->assertOk()
            ->assertSee('Sudah dikirim 3 kg')
            ->assertSee('Kurang 0,5 kg, menyusul')
            ->assertSee('Stok Kurang');

        $this->get(route('pusat.kiriman.lihat', $kiriman))
            ->assertOk()
            ->assertSee('3 kg')
            ->assertDontSee('Diterima pas');
    }

    public function test_cabang_melihat_kiriman_yang_perlu_dihitung(): void
    {
        $this->kirim();
        $kiriman = Kiriman::first();
        $kd = $kiriman->detail()->first();

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang'))
            ->assertOk()
            ->assertSee(route('cabang.terima-barang.lihat', $kiriman), false)
            ->assertSee('Hitung sekarang');

        $this->get(route('cabang.terima-barang.lihat', $kiriman))
            ->assertOk()
            ->assertSee('Layak Pakai')
            ->assertSee('4 kg');

        $this->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
            $kd->id => ['qty' => '4', 'kondisi' => KirimanDetail::KONDISI_BAIK, 'catatan' => ''],
        ]]);

        $this->get(route('cabang.terima-barang'))->assertOk()->assertDontSee('Hitung sekarang');
        $this->get(route('cabang.terima-barang.lihat', $kiriman))->assertOk()->assertSee('4 kg')->assertDontSee('dikirim 4 kg');
    }

    public function test_form_terima_berupa_popup_dengan_isian_jumlah_dan_kotak_kondisi(): void
    {
        $this->kirim('2');
        $kiriman = Kiriman::first();

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang'))
            ->assertSee('href="'.route('cabang.terima-barang.lihat', $kiriman).'" data-popup="panjang">', false);

        $this->app->forgetScopedInstances();

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.terima-barang.lihat', $kiriman))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('data-isian-jumlah', false)
            ->assertSee('type="radio" name="terima['.$kiriman->detail()->first()->id.'][kondisi]" value="baik" checked', false)
            ->assertSee('Barang Tidak Ada');
    }
}
