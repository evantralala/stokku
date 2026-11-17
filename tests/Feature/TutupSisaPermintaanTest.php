<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Satuan;
use App\Models\User;
use App\Services\JumlahMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TutupSisaPermintaanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;
    private BahanBaku $garam;
    private Permintaan $permintaan;

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
        $kategori = Kategori::create(['nama' => 'Kering'])->id;
        $bahan = fn (string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = $bahan('Nori');
        $this->garam = $bahan('Garam');

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 10000, 'diperbarui_pada' => now()]);

        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris' => [
                ['bahan_baku_id' => $this->nori->id, 'qty' => '4'],
                ['bahan_baku_id' => $this->garam->id, 'qty' => '2'],
            ],
        ]);
        $this->permintaan = Permintaan::first();
    }

    private function setujui(array $bahan): void
    {
        $keputusan = $this->permintaan->detail()->whereIn('bahan_baku_id', array_map(fn ($b) => $b->id, $bahan))->get()
            ->mapWithKeys(fn (PermintaanDetail $d) => [$d->id => ['aksi' => 'setujui', 'qty' => $d->bahan_baku_id === $this->nori->id ? '4' : '2']])
            ->all();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $this->permintaan), ['keputusan' => $keputusan]);
    }

    private function kirimSebagian(): void
    {
        $this->setujui([$this->nori, $this->garam]);
        $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.simpan', $this->permintaan))->assertSessionHasNoErrors();
    }

    public function test_tombol_tutup_sisa_hanya_untuk_admin_utama(): void
    {
        $this->kirimSebagian();

        $this->actingAs($this->adminUtama)->get(route('admin-utama.kiriman'))
            ->assertOk()->assertSee('Tutup Sisa')->assertSee(route('admin-utama.kiriman.tutup-sisa', $this->permintaan), false);

        $this->actingAs($this->adminPusat)->get(route('pusat.kiriman'))
            ->assertOk()->assertDontSee('Tutup Sisa');

        $this->actingAs($this->adminPusat)->patch(route('admin-utama.kiriman.tutup-sisa', $this->permintaan))->assertForbidden();
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $this->permintaan->fresh()->status);
    }

    public function test_tutup_sisa_menyelesaikan_permintaan_tanpa_mengubah_jumlah(): void
    {
        $this->kirimSebagian();
        $saldoSebelum = DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->pluck('saldo', 'bahan_baku_id')->all();

        $this->actingAs($this->adminUtama)->patch(route('admin-utama.kiriman.tutup-sisa', $this->permintaan))
            ->assertRedirect(route('admin-utama.kiriman'))
            ->assertSessionHas('sukses');

        $p = $this->permintaan->fresh();
        $this->assertSame(Permintaan::STATUS_SELESAI, $p->status);
        $this->assertNotNull($p->sisa_ditutup_pada);
        $this->assertSame($this->adminUtama->id, (int) $p->sisa_ditutup_oleh);
        $this->assertSame(['Sisa Ditutup', 'b-selesai'], $p->tampilanStatus());

        $garam = $p->detail()->where('bahan_baku_id', $this->garam->id)->first();
        $this->assertEquals(2000, (float) $garam->qty_disetujui);
        $this->assertEquals(0, (float) $garam->qty_dikirim);
        $this->assertEquals($saldoSebelum, DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->pluck('saldo', 'bahan_baku_id')->all());

        $this->actingAs($this->adminUtama)->get(route('admin-utama.kiriman'))->assertSee('Semua sudah dikirim.');
        $this->assertSame(0, app(JumlahMenu::class)->untuk($this->adminUtama)['permintaan_belum_dikirim']);

        $this->actingAs($this->adminCabang)->get(route('cabang.permintaan.lihat', $p))->assertSee('Sisa Ditutup');
    }

    public function test_permintaan_yang_sisanya_ditutup_tidak_bisa_dikirim_lagi(): void
    {
        $this->kirimSebagian();
        $this->actingAs($this->adminUtama)->patch(route('admin-utama.kiriman.tutup-sisa', $this->permintaan));

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->garam->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);
        $jumlahKiriman = $this->permintaan->kiriman()->count();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.kiriman.simpan', $this->permintaan))->assertSessionHas('galat');
        $this->actingAs($this->adminUtama)->patch(route('admin-utama.kiriman.tutup-sisa', $this->permintaan))->assertSessionHas('galat');
        $this->assertSame($jumlahKiriman, $this->permintaan->kiriman()->count());
    }

    public function test_tutup_sisa_ditolak_bila_masih_ada_barang_menunggu_persetujuan(): void
    {
        $this->setujui([$this->nori]);

        $this->actingAs($this->adminUtama)->patch(route('admin-utama.kiriman.tutup-sisa', $this->permintaan))
            ->assertSessionHas('galat', fn (string $pesan) => str_contains($pesan, 'menunggu persetujuan'));

        $this->assertSame(Permintaan::STATUS_MENUNGGU, $this->permintaan->fresh()->status);
        $this->assertNull($this->permintaan->fresh()->sisa_ditutup_pada);
    }
}
