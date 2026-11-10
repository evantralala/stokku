<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Kiriman;
use App\Models\Menu;
use App\Models\Opname;
use App\Models\PenjualanDetail;
use App\Models\PenjualanHarian;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\LaporanService;
use App\Support\Periode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaporanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;
    private LaporanService $laporan;
    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 20, 12));
        $this->laporan = app(LaporanService::class);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
    }

    private function periode(): Periode
    {
        return Periode::antara(Carbon::create(2026, 10, 1), Carbon::create(2026, 10, 20));
    }

    private function mutasi(Cabang $lokasi, string $jenis, float $jumlah, string $waktu): void
    {
        DB::table('stok_mutasi')->insert([
            'cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => $jumlah, 'jenis' => $jenis,
            'status' => StokMutasi::STATUS_SAH, 'dokumen_tipe' => 'uji', 'dokumen_id' => ++$this->urut, 'dibuat_pada' => $waktu,
        ]);
    }

    public function test_rekap_stok_awal_gerak_per_jenis_dan_akhir(): void
    {
        $this->mutasi($this->pusat, StokMutasi::JENIS_MASUK_SUPPLIER, 5000, '2026-09-25 10:00:00');
        $this->mutasi($this->pusat, StokMutasi::JENIS_MASUK_SUPPLIER, 3000, '2026-10-05 10:00:00');
        $this->mutasi($this->pusat, StokMutasi::JENIS_KIRIM_KELUAR, -2000, '2026-10-06 10:00:00');
        $this->mutasi($this->pusat, StokMutasi::JENIS_KOREKSI_OPNAME, -100, '2026-10-10 10:00:00');
        $this->mutasi($this->pusat, StokMutasi::JENIS_MASUK_SUPPLIER, 9999, '2026-10-21 10:00:00');

        $baris = $this->laporan->rekapStok($this->pusat, $this->periode())->first();

        $this->assertSame(5000.0, $baris['awal']);
        $this->assertSame(3000.0, $baris['jenis'][StokMutasi::JENIS_MASUK_SUPPLIER]);
        $this->assertSame(-2000.0, $baris['jenis'][StokMutasi::JENIS_KIRIM_KELUAR]);
        $this->assertSame(-100.0, $baris['jenis'][StokMutasi::JENIS_KOREKSI_OPNAME]);
        $this->assertSame(5900.0, $baris['akhir']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.stok', ['dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertOk()
            ->assertSee('Gudang Pusat')
            ->assertSee('+3')
            ->assertSee('5,9');
    }

    public function test_penjualan_menu_dan_persentase_bahan_hilang(): void
    {
        $menu = Menu::create(['nama' => 'Shoyu', 'kategori_menu_id' => KategoriMenu::create(['nama' => 'Ramen'])->id]);
        $bk = BarangKeluar::create([
            'nomor' => 'BK-1', 'cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-10', 'jenis' => BarangKeluar::JENIS_PEMAKAIAN,
            'dicatat_oleh' => $this->adminUtama->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
        ]);
        $penjualan = PenjualanHarian::create(['cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-10', 'barang_keluar_id' => $bk->id, 'dicatat_oleh' => $this->adminUtama->id]);
        PenjualanDetail::create(['penjualan_harian_id' => $penjualan->id, 'menu_id' => $menu->id, 'porsi' => 40, 'takaran_resep_json' => []]);

        $this->mutasi($this->cabang, StokMutasi::JENIS_PAKAI_DAPUR, -2000, '2026-10-10 21:00:00');
        $this->mutasi($this->cabang, StokMutasi::JENIS_KOREKSI_OPNAME, -200, '2026-10-15 21:00:00');

        $menuLaporan = $this->laporan->penjualanMenu($this->cabang, $this->periode());
        $this->assertSame(40, $menuLaporan->first()->porsi);

        $bahan = $this->laporan->pemakaianBahan(null, $this->periode())->first();
        $this->assertSame(2000.0, $bahan['pemakaian']);
        $this->assertSame(200.0, $bahan['hilang']);
        $this->assertSame(10.0, $bahan['persen_hilang']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.penjualan', ['dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertOk()
            ->assertSee('Shoyu')
            ->assertSee('10%');
    }

    public function test_selisih_kiriman_dan_opname(): void
    {
        $kiriman = Kiriman::create([
            'nomor' => 'KR-1', 'dari_cabang_id' => $this->pusat->id, 'ke_cabang_id' => $this->cabang->id,
            'status' => Kiriman::STATUS_DICHECKLIST, 'dikirim_pada' => '2026-10-11 08:00:00', 'sampai_pada' => '2026-10-11 10:00:00',
        ]);
        $kiriman->detail()->create([
            'bahan_baku_id' => $this->nori->id, 'qty_kirim' => 4000, 'qty_terima' => 3500,
            'kondisi' => 'rusak', 'catatan_penerima' => 'Basah', 'dichecklist_pada' => '2026-10-11 10:00:00',
        ]);

        $opname = Opname::create([
            'nomor' => 'OP-1', 'cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-12',
            'petugas_id' => $this->adminUtama->id, 'status' => Opname::STATUS_DISETUJUI,
        ]);
        $opname->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty_sistem' => 1000, 'qty_fisik' => 990]);

        $this->assertCount(1, $this->laporan->selisihKiriman($this->cabang, $this->periode()));
        $this->assertCount(1, $this->laporan->selisihOpname(null, $this->periode()));

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.selisih', ['dari' => '2026-10-01', 'sampai' => '2026-10-20']))
            ->assertOk()
            ->assertSee('KR-1')
            ->assertSee('-0,5 kg')
            ->assertSee('OP-1')
            ->assertSee('-0,01 kg');
    }

    public function test_barang_rusak_per_bahan(): void
    {
        foreach (['Basah', 'Sobek', 'Jatuh'] as $alasan) {
            $bk = BarangKeluar::create([
                'nomor' => 'BK-R'.(++$this->urut), 'cabang_id' => $this->cabang->id, 'tanggal' => '2026-10-08',
                'jenis' => BarangKeluar::JENIS_RUSAK, 'dicatat_oleh' => $this->adminUtama->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
            ]);
            $bk->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty' => 250, 'sebab' => BarangKeluarDetail::SEBAB_RUSAK, 'penjelasan' => $alasan]);
        }

        $hasil = $this->laporan->barangRusak(null, $this->periode());
        $this->assertSame(['per_bahan'], array_keys($hasil));
        $this->assertSame(3, (int) $hasil['per_bahan']->first()->kali);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.laporan.rusak'))
            ->assertOk()
            ->assertSee('0,75 kg')
            ->assertDontSee('Per Sebab');
    }

    public function test_periode_salah_diganti_bawaan_dan_tertukar_dibetulkan(): void
    {
        $bawaan = Periode::dariRequest(Request::create('/', 'GET', ['dari' => 'kemarin', 'sampai' => '2026-13-40']));
        $this->assertSame('2026-10-01', $bawaan->dariTanggal());
        $this->assertSame('2026-10-20', $bawaan->sampaiTanggal());

        $tertukar = Periode::dariRequest(Request::create('/', 'GET', ['dari' => '2026-10-15', 'sampai' => '2026-10-02']));
        $this->assertSame('2026-10-02', $tertukar->dariTanggal());
        $this->assertSame('2026-10-15', $tertukar->sampaiTanggal());
    }

    public function test_hanya_admin_utama(): void
    {
        $admin = User::factory()->adminCabang($this->cabang->id)->create();

        $this->actingAs($admin)->get(route('admin-utama.laporan'))->assertForbidden();
        $this->actingAs($this->adminUtama)->get(route('admin-utama.laporan'))->assertOk()->assertSee('Barang Rusak')->assertDontSee('Kinerja supplier');
    }
}
