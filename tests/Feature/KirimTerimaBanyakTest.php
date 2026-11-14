<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\KirimanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Checklist pilih banyak: kirim beberapa permintaan sekaligus dan terima beberapa kiriman sekaligus. */
class KirimTerimaBanyakTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $jati;
    private Cabang $kenanga;
    private User $adminUtama;
    private User $adminPusat;
    private User $adminJati;
    private User $adminKenanga;
    private BahanBaku $nori;
    private BahanBaku $mie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->jati = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->kenanga = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminJati = User::factory()->adminCabang($this->jati->id)->create();
        $this->adminKenanga = User::factory()->adminCabang($this->kenanga->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kering = Kategori::create(['nama' => 'Kering']);
        $bahan = fn (string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kering->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = $bahan('Nori');
        $this->mie = $bahan('Mie ramen');

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 10000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->mie->id, 'saldo' => 1000, 'diperbarui_pada' => now()],
        ]);
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    /** @param  array<int, string>  $barang  [bahan id => qty kg] */
    private function permintaanDisetujui(User $admin, array $barang): Permintaan
    {
        $this->actingAs($admin)->post(route('cabang.permintaan.simpan'), [
            'baris'     => collect($barang)->map(fn ($qty, $id) => ['bahan_baku_id' => $id, 'qty' => $qty])->values()->all(),
        ])->assertSessionHasNoErrors();

        $permintaan = Permintaan::latest('id')->first();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
            'keputusan' => $permintaan->detail()->get()->mapWithKeys(fn ($d) => [$d->id => ['aksi' => 'setujui', 'qty' => $barang[$d->bahan_baku_id]]])->all(),
        ])->assertSessionHasNoErrors();

        return $permintaan;
    }

    public function test_kirim_yang_dicentang_dan_stok_kurang_dikirim_sebagian(): void
    {
        $jati = $this->permintaanDisetujui($this->adminJati, [$this->nori->id => '4']);
        $kenanga = $this->permintaanDisetujui($this->adminKenanga, [$this->nori->id => '3', $this->mie->id => '2']);

        $this->actingAs($this->adminPusat)
            ->get(route('pusat.kiriman'))
            ->assertOk()
            ->assertSee('form="pilih-kirim" name="permintaan[]" value="'.$jati->id.'" data-pilih-baris', false)
            ->assertSee('data-pilih-semua="pilih-kirim"', false)
            ->assertSee('<label class="pilih-semua-hp">', false)
            ->assertSee('id="pilih-kirim" method="POST" action="'.route('pusat.kiriman.banyak.simpan').'"', false)
            ->assertSee('Stok kurang: Mie ramen. Dikirim sebagian.')
            ->assertSee('Kirim yang Dicentang');

        $this->post(route('pusat.kiriman.banyak.simpan'), ['permintaan' => [$jati->id, $kenanga->id]])
            ->assertRedirect(route('pusat.kiriman'))
            ->assertSessionHas('sukses', fn (string $pesan) => str_contains($pesan, '2 kiriman tercatat')
                && str_contains($pesan, '1 permintaan baru terkirim sebagian'));

        $this->assertSame(2, Kiriman::count());
        $this->assertSame(3000.0, $this->saldo($this->pusat, $this->nori));
        $this->assertSame(0.0, $this->saldo($this->pusat, $this->mie));
        $this->assertSame(Permintaan::STATUS_SELESAI, $jati->fresh()->status);
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $kenanga->fresh()->status);
        $this->assertSame(1000.0, $kenanga->detail()->where('bahan_baku_id', $this->mie->id)->sole()->utang_kiriman);
    }

    public function test_admin_utama_bisa_kirim_banyak_dan_pilihan_kosong_ditolak(): void
    {
        $permintaan = $this->permintaanDisetujui($this->adminJati, [$this->nori->id => '2']);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.kiriman.banyak.simpan'), [])
            ->assertRedirect(route('admin-utama.kiriman'))
            ->assertSessionHas('galat', 'Centang minimal satu permintaan yang masih perlu dikirim.');

        $this->post(route('admin-utama.kiriman.banyak.simpan'), ['permintaan' => [$permintaan->id]])->assertSessionHas('sukses');
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->nori));
    }

    public function test_terima_banyak_kiriman_sekaligus_sesuai_semua(): void
    {
        $satu = $this->permintaanDisetujui($this->adminJati, [$this->nori->id => '2']);
        $dua = $this->permintaanDisetujui($this->adminJati, [$this->mie->id => '1']);
        $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.banyak.simpan'), ['permintaan' => [$satu->id, $dua->id]]);
        $lain = $this->permintaanDisetujui($this->adminKenanga, [$this->nori->id => '1']);
        $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.banyak.simpan'), ['permintaan' => [$lain->id]]);
        $kirimanLain = Kiriman::where('ke_cabang_id', $this->kenanga->id)->sole();
        $milikJati = Kiriman::where('ke_cabang_id', $this->jati->id)->pluck('id')->all();
        $this->assertCount(2, $milikJati);

        $this->actingAs($this->adminJati)
            ->get(route('cabang.terima-barang'))
            ->assertOk()
            ->assertSee('form="pilih-terima" name="kiriman[]" value="'.$milikJati[0].'" data-pilih-baris', false)
            ->assertSee('<label class="pilih-semua-hp">', false)
            ->assertSee('data-pilih-semua="pilih-terima"', false)
            ->assertSee('Terima yang Dicentang')
            ->assertDontSee('value="'.$kirimanLain->id.'" data-pilih-baris', false);

        $this->post(route('cabang.terima-barang.banyak.simpan'), ['kiriman' => [...$milikJati, $kirimanLain->id]])
            ->assertRedirect(route('cabang.terima-barang'))
            ->assertSessionHas('sukses', fn (string $pesan) => str_starts_with($pesan, '2 kiriman diterima sesuai'));

        $this->assertSame(2000.0, $this->saldo($this->jati, $this->nori));
        $this->assertSame(1000.0, $this->saldo($this->jati, $this->mie));
        $this->assertSame([Kiriman::STATUS_SELESAI], Kiriman::whereKey($milikJati)->pluck('status')->unique()->values()->all());
        $this->assertSame(Kiriman::STATUS_DIKIRIM, $kirimanLain->fresh()->status);

        $this->post(route('cabang.terima-barang.banyak.simpan'), ['kiriman' => [$kirimanLain->id]])
            ->assertSessionHas('galat', 'Centang minimal satu kiriman yang belum diterima.');
        $this->get(route('cabang.terima-barang'))->assertDontSee('data-pilih-baris', false)->assertDontSee('Terima yang Dicentang');
    }

    public function test_gudang_pusat_terima_banyak_retur_cabang(): void
    {
        DB::table('stok_saldo')->insert(['cabang_id' => $this->jati->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 3000, 'diperbarui_pada' => now()]);
        $layanan = app(KirimanService::class);
        $layanan->kirimLangsung($this->jati, $this->pusat, $this->adminJati, [$this->nori->id => 1]);
        $layanan->kirimLangsung($this->jati, $this->pusat, $this->adminJati, [$this->nori->id => 0.5]);

        $this->actingAs($this->adminPusat)
            ->post(route('pusat.terima-barang.banyak.simpan'), ['kiriman' => Kiriman::pluck('id')->all()])
            ->assertRedirect(route('pusat.terima-barang'))
            ->assertSessionHas('sukses');

        $this->assertSame(11500.0, $this->saldo($this->pusat, $this->nori));
        $this->assertSame(1500.0, $this->saldo($this->jati, $this->nori));
    }
}
