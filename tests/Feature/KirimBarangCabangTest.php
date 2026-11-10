<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\Notifikasi;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Cabang membuat kiriman sendiri (ke cabang lain atau retur ke Gudang Pusat) beserta surat jalannya, tanpa persetujuan. */
class KirimBarangCabangTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->jati = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->kenanga = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminJati = User::factory()->adminCabang($this->jati->id)->create();
        $this->adminKenanga = User::factory()->adminCabang($this->kenanga->id)->create();

        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => Satuan::create(['nama' => 'gram'])->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->jati->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);
    }

    private function saldo(Cabang $lokasi): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $this->nori->id)->value('saldo');
    }

    private function kirim(Cabang $tujuan, string $qty)
    {
        return $this->actingAs($this->adminJati)->post(route('cabang.kirim-barang.simpan'), [
            'ke_cabang_id' => $tujuan->id,
            'baris'        => [['bahan_baku_id' => $this->nori->id, 'qty' => $qty]],
            'catatan'      => 'Titipan stok',
        ]);
    }

    public function test_menu_dan_form_kirim_barang_cabang(): void
    {
        $this->actingAs($this->adminJati)
            ->get(route('cabang.dashboard'))
            ->assertSee(route('cabang.kirim-barang'), false);

        $this->get(route('cabang.kirim-barang'))->assertOk()->assertSee(route('cabang.kirim-barang.buat'), false);

        $this->get(route('cabang.kirim-barang.buat'))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('Gudang Pusat (retur)')
            ->assertSee('Cabang Kenanga')
            ->assertDontSee('<option value="'.$this->jati->id.'"', false)
            ->assertSee('Stok Cabang Jati 5 kg');
    }

    public function test_kirim_ke_cabang_lain_tanpa_persetujuan_lalu_diterima(): void
    {
        $this->kirim($this->kenanga, '2')->assertSessionHasNoErrors()->assertSessionHas('sukses');

        $kiriman = Kiriman::sole();
        $this->assertSame([$this->jati->id, $this->kenanga->id, null, Kiriman::STATUS_DIKIRIM], [
            $kiriman->dari_cabang_id, $kiriman->ke_cabang_id, $kiriman->permintaan_id, $kiriman->status,
        ]);
        $this->assertStringStartsWith('KR-JTI-', $kiriman->nomor);
        $this->assertSame(3000.0, $this->saldo($this->jati));

        $this->assertSame(route('cabang.terima-barang.lihat', $kiriman, false), Notifikasi::where('user_id', $this->adminKenanga->id)->sole()->tautan);
        $this->assertSame('Kiriman dari Cabang Jati ke Cabang Kenanga', Notifikasi::where('user_id', $this->adminUtama->id)->sole()->judul);

        $this->get(route('cabang.kirim-barang.lihat', $kiriman))
            ->assertOk()
            ->assertSee(route('cabang.kirim-barang.surat-jalan', $kiriman), false)
            ->assertSee('Tanpa permintaan')
            ->assertSee('Titipan stok');

        $pdf = $this->get(route('cabang.kirim-barang.surat-jalan', $kiriman))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringContainsString('(Surat Jalan) Tj', $pdf);
        $this->assertStringContainsString('(Dari Cabang Jati ke Cabang Kenanga) Tj', $pdf);

        $this->actingAs($this->adminKenanga)
            ->get(route('cabang.terima-barang'))
            ->assertSee('Cabang Jati')
            ->assertSee($kiriman->nomor);
        $this->get(route('cabang.kirim-barang.lihat', $kiriman))->assertNotFound();

        $this->post(route('cabang.terima-barang.simpan', $kiriman), ['kesesuaian' => 'sesuai'])->assertSessionHas('sukses');

        $this->assertSame(2000.0, $this->saldo($this->kenanga));
        $this->assertSame(Kiriman::STATUS_SELESAI, $kiriman->fresh()->status);
        $this->assertSame(route('cabang.kirim-barang.lihat', $kiriman, false), Notifikasi::where('user_id', $this->adminJati->id)->sole()->tautan);
    }

    public function test_retur_ke_gudang_pusat_diterima_admin_pusat(): void
    {
        $this->kirim($this->pusat, '1,5')->assertSessionHasNoErrors();
        $kiriman = Kiriman::sole();

        $this->actingAs($this->adminPusat)
            ->get(route('pusat.dashboard'))
            ->assertSee(route('pusat.terima-barang'), false);

        $this->get(route('pusat.terima-barang'))->assertOk()->assertSee($kiriman->nomor)->assertSee('Cabang Jati');
        $this->get(route('pusat.terima-barang.lihat', $kiriman))->assertOk()->assertSee('Sesuai Semua');

        $this->post(route('pusat.terima-barang.simpan', $kiriman), ['kesesuaian' => 'sesuai'])
            ->assertRedirect(route('pusat.terima-barang.lihat', $kiriman))
            ->assertSessionHas('sukses');

        $this->assertSame(1500.0, $this->saldo($this->pusat));
        $this->assertSame(3500.0, $this->saldo($this->jati));
        $this->assertSame(2, StokMutasi::where('dokumen_tipe', Kiriman::DOKUMEN_TIPE)->sah()->count());
    }

    public function test_tujuan_diri_sendiri_dan_stok_kurang_ditolak(): void
    {
        $this->kirim($this->jati, '1')->assertSessionHasErrors('ke_cabang_id');

        $this->kirim($this->kenanga, '6')
            ->assertSessionHas('galat', 'Stok Nori di Cabang Jati tidak cukup (tersisa 5 kg). Kurangi jumlahnya atau kirim nanti.');

        $this->actingAs($this->adminJati)->post(route('cabang.kirim-barang.simpan'), ['ke_cabang_id' => $this->kenanga->id, 'baris' => []])
            ->assertSessionHasErrors('baris');

        $this->assertSame(0, Kiriman::count());
        $this->assertSame(5000.0, $this->saldo($this->jati));
    }

    public function test_tautan_riwayat_stok_mengikuti_sisi_pengirim_atau_penerima(): void
    {
        $this->kirim($this->kenanga, '2');
        $kiriman = Kiriman::sole();
        $this->actingAs($this->adminKenanga)->post(route('cabang.terima-barang.simpan', $kiriman), ['kesesuaian' => 'sesuai']);

        $this->actingAs($this->adminJati)
            ->get(route('cabang.stok.mutasi', ['tampil' => 'transaksi']))
            ->assertSee(route('cabang.kirim-barang.lihat', $kiriman), false);

        $this->actingAs($this->adminKenanga)
            ->get(route('cabang.stok.mutasi', ['tampil' => 'transaksi']))
            ->assertSee(route('cabang.terima-barang.lihat', $kiriman), false);
    }

    public function test_surat_jalan_kiriman_masuk_tidak_lagi_diunduh_cabang_penerima(): void
    {
        $this->assertFalse(Route::has('cabang.terima-barang.surat-jalan'));
        $this->assertFalse(Route::has('pusat.terima-barang.surat-jalan'));
    }
}
