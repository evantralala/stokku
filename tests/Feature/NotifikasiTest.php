<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Kiriman;
use App\Models\KirimanDetail;
use App\Models\Menu;
use App\Models\Notifikasi;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotifikasiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminUtamaNonaktif;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminUtamaNonaktif = User::factory()->adminUtama()->nonaktif()->create();
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
    }

    private function notifUntuk(User $u): \Illuminate\Support\Collection
    {
        return Notifikasi::query()->milik($u->id)->orderBy('id')->get();
    }

    private function ajukanDanSetujui(): Permintaan
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => [['bahan_baku_id' => $this->nori->id, 'qty' => '4']],
        ]);
        $permintaan = Permintaan::first();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
            'keputusan' => [$permintaan->detail()->first()->id => ['aksi' => 'setujui', 'qty' => '4']],
        ]);

        return $permintaan;
    }

    public function test_alur_permintaan_sampai_kiriman_memberi_tahu_pihak_yang_tepat(): void
    {
        $permintaan = $this->ajukanDanSetujui();

        $adminUtama = $this->notifUntuk($this->adminUtama);
        $this->assertSame('Permintaan baru dari Cabang Jati', $adminUtama->first()->judul);
        $this->assertSame(route('admin-utama.persetujuan.permintaan', $permintaan, false), $adminUtama->first()->tautan);
        $this->assertCount(0, $this->notifUntuk($this->adminUtamaNonaktif));

        $this->assertSame('Permintaan '.$permintaan->nomor.' diputuskan', $this->notifUntuk($this->adminCabang)->first()->judul);
        $this->assertSame('Siap dikirim ke Cabang Jati', $this->notifUntuk($this->adminPusat)->first()->judul);

        $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.simpan', $permintaan), [
            'jumlah' => [$permintaan->detail()->first()->id => '4'],
        ]);
        $kiriman = Kiriman::first();
        $this->assertStringContainsString('dalam perjalanan', $this->notifUntuk($this->adminCabang)->last()->judul);

        $this->actingAs($this->adminCabang)->post(route('cabang.terima-barang.simpan', $kiriman), ['terima' => [
            $kiriman->detail()->first()->id => ['qty' => '3', 'kondisi' => KirimanDetail::KONDISI_RUSAK, 'catatan' => 'Basah'],
        ]]);

        $this->assertSame('Kiriman diterima dengan selisih: '.$kiriman->nomor, $this->notifUntuk($this->adminPusat)->last()->judul);
        $this->assertSame('Selisih kiriman di Cabang Jati', $this->notifUntuk($this->adminUtama)->last()->judul);
    }

    public function test_keputusan_barang_masuk_sampai_ke_admin_pusat(): void
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.barang-masuk.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '1']],
        ]);
        $dokumen = \App\Models\BarangMasuk::first();
        $this->assertSame('Barang masuk baru: Gudang Pusat', $this->notifUntuk($this->adminUtama)->last()->judul);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.barang-masuk.putuskan', $dokumen), [
            'aksi' => 'revisi', 'catatan' => 'Cek ulang jumlahnya',
        ]);

        $notif = $this->notifUntuk($this->adminPusat)->last();
        $this->assertSame('Barang masuk '.$dokumen->nomor.': diminta perbaikan', $notif->judul);
        $this->assertSame('Cek ulang jumlahnya', $notif->keterangan);
        $this->assertSame(route('pusat.barang-masuk.lihat', $dokumen, false), $notif->tautan);
    }

    public function test_stok_minus_karena_penjualan_memberi_tahu_admin_utama(): void
    {
        $menu = Menu::create(['nama' => 'Shoyu', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen'])->id]);
        Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => 10, 'berlaku_sejak' => now()->toDateString()]);

        $this->actingAs($this->adminCabang)->post(route('cabang.penjualan.simpan'), [
            'tanggal' => now()->toDateString(), 'porsi' => [$menu->id => '5'],
        ]);

        $notif = $this->notifUntuk($this->adminUtama)->last();
        $this->assertSame('Stok minus di Cabang Jati', $notif->judul);
        $this->assertStringContainsString('Nori', $notif->keterangan);
    }

    public function test_tolak_otomatis_memberi_tahu_cabang(): void
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => [['bahan_baku_id' => $this->nori->id, 'qty' => '1']],
        ]);

        $this->travel(25)->hours();
        $this->artisan('persetujuan:tolak-otomatis');

        $this->assertStringContainsString('ditolak otomatis', $this->notifUntuk($this->adminCabang)->last()->judul);
    }

    public function test_lonceng_buka_dan_tandai_semua_dibaca(): void
    {
        $this->ajukanDanSetujui();
        $notif = $this->notifUntuk($this->adminCabang)->first();

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.dashboard'))
            ->assertSee('<span class="lonceng-angka" aria-hidden="true">1</span>', false);

        $this->actingAs($this->adminCabang)
            ->get(route('notifikasi'))
            ->assertOk()
            ->assertSee($notif->judul)
            ->assertSee('1 belum dibaca');

        $this->actingAs($this->adminCabang)
            ->get(route('notifikasi.buka', $notif))
            ->assertRedirect($notif->tautan);
        $this->assertTrue($notif->fresh()->sudahDibaca());

        $this->actingAs($this->adminPusat)->get(route('notifikasi.buka', $notif))->assertNotFound();

        $this->actingAs($this->adminUtama)
            ->patch(route('notifikasi.baca-semua'))
            ->assertSessionHas('sukses');
        $this->assertSame(0, Notifikasi::query()->milik($this->adminUtama->id)->belumDibaca()->count());
    }
}
