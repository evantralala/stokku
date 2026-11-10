<?php

namespace Tests\Feature;

use App\Exceptions\LokasiSedangOpname;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Opname;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpnameTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $admin;
    private Cabang $cabang;
    private BahanBaku $nori;
    private BahanBaku $mie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

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

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 2500, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 4000, 'diperbarui_pada' => now()],
        ]);
    }

    private function mulai(): Opname
    {
        $this->actingAs($this->admin)->post(route('cabang.opname.mulai'));

        return Opname::latest('id')->first();
    }

    private function hitung(Opname $opname, array $qty, string $aksi = 'kirim')
    {
        $hitung = [];
        foreach ($opname->detail as $d) {
            $hitung[$d->id] = ['qty' => $qty[$d->bahan_baku_id] ?? '', 'catatan' => ''];
        }

        return $this->actingAs($this->admin)->put(route('cabang.opname.simpan', $opname), ['aksi' => $aksi, 'hitung' => $hitung]);
    }

    private function saldo(BahanBaku $b): float
    {
        return app(StokService::class)->saldo($this->cabang->id, $b->id);
    }

    public function test_mulai_menyalin_stok_sistem_dan_mengunci_lokasi(): void
    {
        $opname = $this->mulai();

        $this->assertSame('OP-JTI-'.now()->format('ymd').'-001', $opname->nomor);
        $this->assertSame(Opname::STATUS_BERJALAN, $opname->status);
        $this->assertSame(2, $opname->detail()->count());
        $this->assertSame('2500.000', $opname->detail()->where('bahan_baku_id', $this->nori->id)->value('qty_sistem'));
        $this->assertTrue($this->cabang->fresh()->opname_aktif);

        $this->actingAs($this->admin)->post(route('cabang.opname.mulai'))->assertSessionHas('galat');
        $this->assertSame(1, Opname::count());
    }

    public function test_tidak_bisa_mulai_bila_ada_dokumen_menunggu_persetujuan(): void
    {
        app(StokService::class)->catat(
            [['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => -100]],
            StokMutasi::JENIS_RUSAK, 'barang_keluar', 99, $this->admin->id
        );

        $this->actingAs($this->admin)->post(route('cabang.opname.mulai'))->assertSessionHas('galat');
        $this->assertSame(0, Opname::count());
    }

    public function test_halaman_hitung_tidak_menampilkan_stok_sistem(): void
    {
        $opname = $this->mulai();

        $this->actingAs($this->admin)
            ->get(route('cabang.opname.lihat', $opname))
            ->assertOk()
            ->assertSee('Nori')
            ->assertSee('dari 2 bahan sudah dihitung')
            ->assertDontSee('2,5')
            ->assertDontSee('2500');
    }

    public function test_hitung_bisa_dicicil_dan_kirim_wajib_lengkap(): void
    {
        $opname = $this->mulai();

        $this->hitung($opname, [$this->nori->id => '2,4'], 'simpan')
            ->assertSessionHas('sukses', 'Hasil hitung tersimpan. Masih 1 bahan yang belum dihitung.');

        $this->hitung($opname->fresh(), [$this->nori->id => '2,4'], 'kirim')->assertSessionHas('galat');
        $this->assertSame(Opname::STATUS_BERJALAN, $opname->fresh()->status);

        $this->hitung($opname->fresh(), [$this->nori->id => '2,4', $this->mie->id => '4'])->assertSessionHas('sukses');
        $this->assertSame(Opname::STATUS_DIKIRIM, $opname->fresh()->status);
        $this->assertSame('-100.000', $opname->detail()->where('bahan_baku_id', $this->nori->id)->value('selisih'));
    }

    public function test_stok_lokasi_terkunci_selama_opname(): void
    {
        $this->mulai();
        $stok = app(StokService::class);

        $stok->catat(
            [['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => 500]],
            StokMutasi::JENIS_KIRIM_MASUK, 'kiriman', 1, $this->admin->id
        );

        $this->expectException(LokasiSedangOpname::class);
        $stok->sahkan('kiriman', 1);
    }

    public function test_admin_utama_menyetujui_dan_stok_disamakan_dengan_hitung_fisik(): void
    {
        $opname = $this->mulai();
        $this->hitung($opname, [$this->nori->id => '2,3', $this->mie->id => '4']);

        // Nori kurang 200 gram dari 2500 = 8%, melewati toleransi 5%.
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan.opname', $opname))
            ->assertOk()
            ->assertSee('Lewat 5%')
            ->assertSee('-0,2 kg');

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.opname.putuskan', $opname), ['aksi' => 'setujui'])
            ->assertSessionHas('sukses');

        $this->assertSame(Opname::STATUS_DISETUJUI, $opname->fresh()->status);
        $this->assertSame(2300.0, $this->saldo($this->nori));
        $this->assertSame(4000.0, $this->saldo($this->mie));
        $this->assertSame(1, StokMutasi::where('jenis', StokMutasi::JENIS_KOREKSI_OPNAME)->count());
        $this->assertFalse($this->cabang->fresh()->opname_aktif);
    }

    public function test_hitung_ulang_tetap_terkunci_dan_tolak_membuka_kunci_tanpa_koreksi(): void
    {
        $opname = $this->mulai();
        $this->hitung($opname, [$this->nori->id => '1', $this->mie->id => '4']);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.opname.putuskan', $opname), [
            'aksi' => 'revisi', 'catatan' => 'Hitung ulang nori',
        ]);
        $this->assertSame(Opname::STATUS_BERJALAN, $opname->fresh()->status);
        $this->assertTrue($this->cabang->fresh()->opname_aktif);

        $this->hitung($opname->fresh(), [$this->nori->id => '1', $this->mie->id => '4']);
        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.opname.putuskan', $opname), [
            'aksi' => 'tolak', 'catatan' => 'Tidak masuk akal',
        ]);

        $this->assertSame(Opname::STATUS_BATAL, $opname->fresh()->status);
        $this->assertFalse($this->cabang->fresh()->opname_aktif);
        $this->assertSame(2500.0, $this->saldo($this->nori));
    }

    public function test_petugas_membatalkan_opname_membuka_kunci(): void
    {
        $opname = $this->mulai();

        $this->actingAs($this->admin)
            ->patch(route('cabang.opname.batal', $opname))
            ->assertRedirect(route('cabang.opname'));

        $this->assertSame(Opname::STATUS_BATAL, $opname->fresh()->status);
        $this->assertFalse($this->cabang->fresh()->opname_aktif);
    }

    public function test_persetujuan_opname_menaruh_bahan_yang_sesuai_di_lipatan(): void
    {
        $opname = $this->mulai();
        $this->hitung($opname, [$this->nori->id => '2,3', $this->mie->id => '4']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan.opname', $opname))
            ->assertOk()
            ->assertSeeInOrder(['Nori', '2,5 kg', '2,3 kg', '-0,2 kg', 'Lewat 5%', '1 bahan sesuai', 'Mie ramen', 'Sesuai']);
    }

    public function test_halaman_hitung_memakai_isian_jumlah_dan_kotak_cari(): void
    {
        $opname = $this->mulai();

        $this->actingAs($this->admin)
            ->get(route('cabang.opname.lihat', $opname))
            ->assertOk()
            ->assertSee('data-opname-cari data-cari-utama', false)
            ->assertSee('class="isian-jumlah ringkas" data-isian-jumlah', false)
            ->assertSee('placeholder="Belum" data-opname-qty', false);
    }
}
