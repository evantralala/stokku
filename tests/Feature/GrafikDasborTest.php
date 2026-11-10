<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\GrafikDasborService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Grafik dashboard: transaksi masuk/keluar per bulan (per dokumen), banner sapaan, dan donat kondisi stok. */
class GrafikDasborTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $beras;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $gram = Satuan::create(['nama' => 'gram']);
        $kategori = Kategori::create(['nama' => 'Bahan pokok']);
        $bahan = fn (string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
        $this->beras = $bahan('Beras');
        $this->nori = $bahan('Nori');
    }

    private function mutasi(Cabang $lokasi, BahanBaku $bahan, float $jumlah, string $jenis, string $tipe, int $id, string $waktu, string $status = StokMutasi::STATUS_SAH): void
    {
        DB::table('stok_mutasi')->insert([
            'cabang_id' => $lokasi->id, 'bahan_baku_id' => $bahan->id, 'jumlah' => $jumlah, 'jenis' => $jenis, 'status' => $status,
            'dokumen_tipe' => $tipe, 'dokumen_id' => $id, 'dibuat_pada' => $waktu,
        ]);
    }

    public function test_menghitung_dokumen_masuk_dan_keluar_per_bulan(): void
    {
        $this->mutasi($this->pusat, $this->beras, 1000, StokMutasi::JENIS_MASUK_SUPPLIER, 'barang_masuk', 1, '2026-10-02 08:00:00');
        $this->mutasi($this->pusat, $this->nori, 500, StokMutasi::JENIS_MASUK_SUPPLIER, 'barang_masuk', 1, '2026-10-02 08:00:00');
        $this->mutasi($this->pusat, $this->beras, -200, StokMutasi::JENIS_KIRIM_KELUAR, 'kiriman', 1, '2026-09-15 09:00:00');
        $this->mutasi($this->cabang, $this->beras, 200, StokMutasi::JENIS_KIRIM_MASUK, 'kiriman', 1, '2026-09-16 09:00:00');
        $this->mutasi($this->pusat, $this->beras, 50, StokMutasi::JENIS_KOREKSI_OPNAME, 'opname', 1, '2026-08-30 17:00:00');
        $this->mutasi($this->pusat, $this->nori, -20, StokMutasi::JENIS_KOREKSI_OPNAME, 'opname', 1, '2026-08-30 17:00:00');
        $this->mutasi($this->pusat, $this->beras, 9000, StokMutasi::JENIS_STOK_AWAL, 'stok_awal', 1, '2026-10-01 07:00:00');
        $this->mutasi($this->pusat, $this->beras, 300, StokMutasi::JENIS_MASUK_SUPPLIER, 'barang_masuk', 2, '2026-10-05 08:00:00', StokMutasi::STATUS_MENUNGGU);
        $this->mutasi($this->pusat, $this->beras, 300, StokMutasi::JENIS_MASUK_SUPPLIER, 'barang_masuk', 3, '2026-03-31 08:00:00');

        $layanan = app(GrafikDasborService::class);

        $tahun = $layanan->pergerakan(null, 'tahun-ini');
        $this->assertCount(12, $tahun);
        $this->assertSame(['Jan', 'Des'], [$tahun[0]['label'], $tahun[11]['label']]);
        $this->assertSame([[1, 0], [0, 0], [1, 1], [1, 1], [1, 0]], array_map(fn ($i) => [$tahun[$i]['masuk'], $tahun[$i]['keluar']], [2, 3, 7, 8, 9]));

        $bulanIni = $layanan->pergerakan(null, 'bulan-ini');
        $this->assertCount(31, $bulanIni);
        $this->assertSame([1, 0], [$bulanIni[1]['masuk'], $bulanIni[1]['keluar']], '2 Okt: satu dokumen barang masuk');
        $this->assertSame(1, array_sum(array_column($bulanIni, 'masuk')));

        $bulanLalu = $layanan->pergerakan($this->pusat, 'bulan-lalu');
        $this->assertCount(30, $bulanLalu);
        $this->assertSame([0, 1], [$bulanLalu[14]['masuk'], $bulanLalu[14]['keluar']], '15 Sep: kiriman keluar dari pusat');
        $this->assertSame(0, array_sum(array_column($bulanLalu, 'masuk')));

        $this->assertSame(0, array_sum(array_column($layanan->pergerakan(null, 'tahun-lalu'), 'masuk')));
        $this->assertSame('bulan-ini', GrafikDasborService::periode('sembarang'));
    }

    public function test_grafik_penjualan_per_hari_dan_per_bulan_per_cabang(): void
    {
        $lain = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $menu = \App\Models\Menu::create(['nama' => 'Shoyu', 'kategori_menu_id' => \App\Models\KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1])->id]);
        $jual = function (Cabang $c, string $tanggal, int $porsi) use ($menu) {
            $id = DB::table('penjualan_harian')->insertGetId(['cabang_id' => $c->id, 'tanggal' => $tanggal, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('penjualan_detail')->insert(['penjualan_harian_id' => $id, 'menu_id' => $menu->id, 'porsi' => $porsi, 'takaran_resep_json' => '[]']);
        };
        $jual($this->cabang, '2026-10-05', 12);
        $jual($lain, '2026-10-05', 8);
        $jual($this->cabang, '2026-09-30', 7);

        $layanan = app(GrafikDasborService::class);
        $this->assertSame(20, $layanan->penjualan(null, 'bulan-ini')[4]['porsi']);
        $this->assertSame(12, $layanan->penjualan($this->cabang, 'bulan-ini')[4]['porsi']);
        $this->assertSame(7, $layanan->penjualan($this->cabang, 'bulan-lalu')[29]['porsi']);
        $this->assertSame([7, 20], [$layanan->penjualan(null, 'tahun-ini')[8]['porsi'], $layanan->penjualan(null, 'tahun-ini')[9]['porsi']]);

        $admin = User::factory()->adminUtama()->create();
        $this->actingAs($admin)->get(route('admin-utama.dashboard', ['jual' => 'bulan-ini', 'jual_cabang' => $lain->id]))
            ->assertOk()->assertSee('Penjualan <span class="num">8</span> porsi', false)->assertSee('di Cabang Kenanga')
            ->assertSee('Semua Cabang')->assertSee('Tahun Lalu');

        $html = $this->actingAs(User::factory()->adminCabang($this->cabang->id)->create())->get(route('cabang.dashboard', ['jual' => 'tahun-ini']))
            ->assertOk()->assertDontSee('Semua Cabang')->getContent();
        $awal = strpos($html, 'id="grafik-penjualan"');
        $this->assertNotFalse($awal, 'grafik penjualan tidak ada di dashboard cabang');
        $kepala = substr($html, $awal, (int) strpos($html, '<nav', $awal) - $awal);
        $judul = trim(preg_replace('/\s+/', ' ', strip_tags('<x '.$kepala)));
        $this->assertStringStartsWith('Penjualan 19 porsi Porsi terjual di Cabang Jati · Tahun 2026', $judul, 'judul grafik penjualan di dashboard cabang');
    }

    public function test_dashboard_menampilkan_sapaan_kartu_angka_dan_grafik(): void
    {
        $admin = User::factory()->adminUtama()->create(['nama' => 'Dewi Lestari']);
        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 1000, 'diperbarui_pada' => now()]);

        $this->actingAs($admin)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['<section class="sapaan">', 'Selamat bertugas, Dewi', 'class="kartu-grid"', 'Pergerakan Stok', 'Kondisi Stok', 'id="antrean-persetujuan"'], false)
            ->assertSee('Oktober 2026')
            ->assertSee('<form class="sapaan-cari" role="search" method="GET" action="'.route('admin-utama.stok').'">', false);
    }

    public function test_kartu_angka_dashboard_bergradasi_dengan_warna_tetap_dan_stok_mengikuti_kondisi(): void
    {
        $admin = User::factory()->adminUtama()->create();
        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 0, 'diperbarui_pada' => now()]);
        DB::table('bahan_baku_minimum')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'minimum' => 500]);

        $this->actingAs($admin)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'class="kartu-angka nada-baik gradasi-ungu"', 'Menunggu Keputusan',
                'class="kartu-angka nada-bahaya gradasi-merah"', 'Stok Habis / Menipis',
                'class="kartu-angka nada-biasa gradasi-biru"', 'Kiriman di Jalan',
            ], false)
            ->assertSee('id="gradasi-batang-masuk"', false);
    }
}
