<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Opname;
use App\Models\Satuan;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Rute simpan/ubah yang belum diuji di berkas lain: data master, logout, dan transaksi Gudang Pusat. */
class RuteTulisTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private Cabang $pusat;
    private Satuan $gram;
    private Kategori $kering;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $this->gram = Satuan::create(['nama' => 'gram']);
        $this->kering = Kategori::create(['nama' => 'Kering']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => $this->kering->id,
            'satuan_dasar_id' => $this->gram->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 3000, 'diperbarui_pada' => now()]);

        $this->izinkanUbahStokPusat();
    }

    private function saldo(): float
    {
        return app(StokService::class)->saldo($this->pusat->id, $this->nori->id);
    }

    public function test_ubah_dan_nonaktifkan_satuan_kategori_kategori_menu_dan_menu(): void
    {
        $this->actingAs($this->adminUtama);

        $this->put(route('admin-utama.satuan.perbarui', $this->gram), ['nama' => 'gr'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin-utama.satuan'));
        $this->assertSame('gr', $this->gram->fresh()->nama);

        $this->put(route('admin-utama.kategori.perbarui', $this->kering), ['nama' => 'Bahan kering', 'urutan' => 2])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin-utama.kategori'));
        $this->assertSame('Bahan kering', $this->kering->fresh()->nama);

        $this->post(route('admin-utama.kategori-menu.simpan'), ['nama' => 'Ramen', 'urutan' => 1])->assertSessionHasNoErrors();
        $ramen = KategoriMenu::where('nama', 'Ramen')->sole();
        $this->put(route('admin-utama.kategori-menu.perbarui', $ramen), ['nama' => 'Ramen kuah', 'urutan' => 3])->assertSessionHasNoErrors();
        $this->assertSame('Ramen kuah', $ramen->fresh()->nama);

        $menu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);

        foreach ([
            ['admin-utama.satuan', $this->gram],
            ['admin-utama.kategori', $this->kering],
            ['admin-utama.kategori-menu', $ramen],
            ['admin-utama.menu', $menu],
        ] as [$rute, $data]) {
            $this->patch(route($rute.'.aktif', $data), ['aktif' => 0])->assertSessionHasNoErrors();
            $this->patch(route($rute.'.aktif', $data), ['aktif' => 1])->assertRedirect(route($rute))->assertSessionHas('sukses');
            $this->assertTrue((bool) $data->fresh()->aktif, $rute);
        }

        $this->patch(route('admin-utama.menu.aktif', $menu), ['aktif' => 'mungkin'])->assertSessionHasErrors('aktif');
    }

    public function test_keluar_mengakhiri_sesi(): void
    {
        $this->actingAs($this->adminPusat)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        $this->get(route('pusat.stok'))->assertRedirect(route('login'));
    }

    public function test_gudang_pusat_tambah_stok_tapi_tidak_bisa_ubah_atau_excel(): void
    {
        $this->actingAs($this->adminPusat)
            ->post(route('pusat.stok.tambah.simpan'), ['bahan_baku_id' => $this->nori->id, 'jumlah' => '1,5'])
            ->assertSessionHasNoErrors()->assertSessionHas('sukses');
        $this->assertSame(4500.0, $this->saldo());

        $this->post('/pusat/stok/'.$this->nori->id.'/ubah', ['stok_lama' => '4500', 'stok_baru' => '4'])->assertNotFound();
        $this->post('/pusat/stok/excel/pratinjau', [])->assertNotFound();
        $this->assertSame(4500.0, $this->saldo());
    }

    public function test_gudang_pusat_perbaiki_lalu_batalkan_barang_rusak(): void
    {
        $data = fn (string $qty) => [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => $qty, 'alasan' => 'Lembap terkena air']],
        ];

        $this->actingAs($this->adminPusat)->post(route('pusat.barang-keluar.simpan'), $data('0,5'))->assertSessionHasNoErrors();
        $dokumen = BarangKeluar::sole();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.barang-keluar.putuskan', $dokumen), [
            'aksi' => 'revisi', 'catatan' => 'Jumlahnya terlalu banyak',
        ]);

        $this->actingAs($this->adminPusat)->put(route('pusat.barang-keluar.perbarui', $dokumen), $data('0,2'))
            ->assertSessionHasNoErrors()->assertRedirect(route('pusat.barang-keluar.lihat', $dokumen));
        $this->assertSame(BarangKeluar::STATUS_MENUNGGU, $dokumen->fresh()->status);
        $this->assertSame('200.000', $dokumen->detail()->value('qty'));

        $this->patch(route('pusat.barang-keluar.batal', $dokumen))->assertRedirect(route('pusat.barang-keluar'));
        $this->assertSame(BarangKeluar::STATUS_BATAL, $dokumen->fresh()->status);
        $this->assertSame(3000.0, $this->saldo());
    }

    public function test_gudang_pusat_simpan_hitungan_lalu_batalkan_opname(): void
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.opname.mulai'));
        $opname = Opname::sole();
        $this->assertTrue($this->pusat->fresh()->opname_aktif);

        $hitung = $opname->detail->mapWithKeys(fn ($d) => [$d->id => ['qty' => '2,5', 'catatan' => '']])->all();

        $this->put(route('pusat.opname.simpan', $opname), ['aksi' => 'simpan', 'hitung' => $hitung])
            ->assertSessionHasNoErrors()->assertRedirect(route('pusat.opname.lihat', $opname));
        $this->assertNotNull($opname->detail()->value('qty_fisik'));

        $this->patch(route('pusat.opname.batal', $opname))->assertRedirect(route('pusat.opname'));
        $this->assertSame(Opname::STATUS_BATAL, $opname->fresh()->status);
        $this->assertFalse($this->pusat->fresh()->opname_aktif);
        $this->assertSame(3000.0, $this->saldo());
    }
}
