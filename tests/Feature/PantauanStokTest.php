<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Pengaturan;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use App\Services\PantauanStokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PantauanStokTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $admin;
    private Cabang $pusat;
    private Cabang $cabang;
    private array $bahan = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(21, 0));
        Pengaturan::create(['kunci' => 'ambang_stok_menipis', 'nilai' => '20']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Kering']);

        // [kode, nama, saldo cabang (gram), minimum (gram)]
        foreach ([
            ['BB-0001', 'Mie ramen', 5000, 2000],
            ['BB-0002', 'Nori', 1100, 1000],
            ['BB-0003', 'Shoyu', 500, 1000],
            ['BB-0004', 'Miso', 0, 1000],
            ['BB-0005', 'Wijen', -300, 0],
        ] as [$kode, $nama, $saldo, $min]) {
            $b = BahanBaku::create([
                'nama' => $nama, 'kategori_id' => $kategori->id,
                'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
                'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
            ]);
            $this->bahan[$nama] = $b;

            DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'saldo' => $saldo, 'diperbarui_pada' => now()]);
            BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'minimum' => $min]);
        }

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->bahan['Nori']->id, 'saldo' => 9000, 'diperbarui_pada' => now()]);
    }

    public function test_status_dan_perkiraan_cukup_hari(): void
    {
        DB::table('stok_mutasi')->insert([
            'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->bahan['Mie ramen']->id, 'jumlah' => -7000,
            'jenis' => 'pakai_dapur', 'status' => 'sah', 'dokumen_tipe' => 'barang_keluar', 'dokumen_id' => 1, 'dibuat_pada' => now()->subDay(),
        ]);

        $daftar = app(PantauanStokService::class)->daftar($this->cabang)->keyBy(fn ($r) => $r['bahan']->nama);

        $this->assertSame(PantauanStokService::STATUS_AMAN, $daftar['Mie ramen']['status']);
        $this->assertSame(PantauanStokService::STATUS_WASPADA, $daftar['Nori']['status']);
        $this->assertSame(PantauanStokService::STATUS_KURANG, $daftar['Shoyu']['status']);
        $this->assertSame(PantauanStokService::STATUS_HABIS, $daftar['Miso']['status']);
        $this->assertSame(PantauanStokService::STATUS_MINUS, $daftar['Wijen']['status']);

        // Pemakaian 7000 g / 7 hari = 1000 g per hari; stok 5000 g cukup 5 hari.
        $this->assertSame(1000.0, $daftar['Mie ramen']['pakai_per_hari']);
        $this->assertSame(5.0, $daftar['Mie ramen']['cukup_hari']);
        $this->assertNull($daftar['Nori']['cukup_hari']);
    }

    public function test_admin_cabang_melihat_stok_sendiri_terurut_dari_yang_paling_gawat(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('Cabang Jati')
            ->assertSeeInOrder(['Wijen', 'Miso', 'Shoyu', 'Nori', 'Mie ramen'])
            ->assertSee('-0,3 kg')
            ->assertDontSee('9 kg');
    }

    public function test_saring_status_dan_cari(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['status' => PantauanStokService::STATUS_KURANG]))
            ->assertSee('Shoyu')
            ->assertDontSee('Mie ramen');

        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['cari' => 'nor']))
            ->assertSee('Nori')
            ->assertDontSee('Shoyu');
    }

    public function test_admin_utama_memilih_lokasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok'))
            ->assertOk()
            ->assertSee('Gudang Pusat')
            ->assertSee('9 kg');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok', ['lokasi' => $this->cabang->id]))
            ->assertSee('-0,3 kg');
    }

    public function test_riwayat_menampilkan_stok_setelah_tiap_mutasi_dan_tautan_dokumen(): void
    {
        $mie = $this->bahan['Mie ramen'];
        DB::table('stok_saldo')->where('cabang_id', $this->cabang->id)->where('bahan_baku_id', $mie->id)->update(['saldo' => 0]);

        $ramen = KategoriMenu::create(['nama' => 'Ramen']);
        $menu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);
        Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $mie->id, 'jumlah' => 150, 'berlaku_sejak' => now()->toDateString()]);

        DB::table('stok_saldo')->where('cabang_id', $this->cabang->id)->where('bahan_baku_id', $mie->id)->update(['saldo' => 3000]);
        $this->actingAs($this->admin)->post(route('cabang.penjualan.simpan'), ['tanggal' => now()->toDateString(), 'porsi' => [$menu->id => '4']]);

        $this->actingAs($this->admin)
            ->get(route('cabang.stok.riwayat', $mie))
            ->assertOk()
            ->assertSee('Pemakaian Dapur')
            ->assertSee('-0,6 kg')
            ->assertSee('2,4 kg')
            ->assertSee(route('cabang.penjualan.lihat', \App\Models\PenjualanHarian::first()), false);
    }

    public function test_admin_pusat_hanya_gudangnya(): void
    {
        $adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $this->actingAs($adminPusat)
            ->get(route('pusat.stok'))
            ->assertOk()
            ->assertSee('Gudang Pusat')
            ->assertSee('9 kg');

        $this->actingAs($adminPusat)->get(route('admin-utama.stok'))->assertForbidden();
    }

    public function test_baris_stok_ringkas_dengan_nama_membuka_intip_bahan(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('href="'.route('cabang.stok.intip', $this->bahan['Shoyu']).'" data-popup="panjang" data-popup-judul="Shoyu"', false)
            ->assertSee('Min. 1 kg')
            ->assertDontSee('>Riwayat Lengkap<', false);
    }

    public function test_tabel_stok_siap_untuk_daftar_sempit_tanpa_kolom_kosong(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('class="tabel-kartu tabel-stok tanpa-ubah"', false)
            ->assertSee('<span class="satuan stok-min-sempit">Min. 1 kg</span>', false);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('class="tabel-kartu tabel-stok ada-kosongkan"', false)
            ->assertSee('stok-min-sempit', false);
    }

    public function test_baris_stok_membawa_data_saringan_langsung(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('data-saring-stok="daftar-stok"', false)
            ->assertSee('data-stok-baris data-lampu="'.PantauanStokService::LAMPU_KUNING.'" data-cari="shoyu"', false)
            ->assertSee('data-cari-utama data-cari-instan', false)
            ->assertSeeInOrder([
                'data-saring-lampu=""', 'Semua', '5',
                'data-saring-lampu="'.PantauanStokService::LAMPU_MERAH.'"', 'Habis', '2',
                'data-saring-lampu="'.PantauanStokService::LAMPU_KUNING.'"', 'Menipis', '2',
                'data-saring-lampu="'.PantauanStokService::LAMPU_HIJAU.'"', 'Aman', '1',
            ], false)
            ->assertSee('data-stok-kosong="data-stok-kosong" hidden="hidden"', false);

        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['cari' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('data-stok-kosong="data-stok-kosong">', false)
            ->assertSee('data-server-cari="tidak-ada"', false);
    }

    public function test_intip_bahan_menampilkan_angka_mutasi_terakhir_dan_tautan(): void
    {
        $mie = $this->bahan['Mie ramen'];
        foreach ([[-700, 'pakai_dapur', 3], [2000, 'kirim_masuk', 2]] as [$jumlah, $jenis, $hari]) {
            DB::table('stok_mutasi')->insert([
                'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $mie->id, 'jumlah' => $jumlah,
                'jenis' => $jenis, 'status' => 'sah', 'dokumen_tipe' => 'barang_keluar', 'dokumen_id' => 1, 'dibuat_pada' => now()->subDays($hari),
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('cabang.stok.intip', $mie))
            ->assertOk()
            ->assertSee('<section class="panel intip-bahan" data-popup-isi>', false)
            ->assertSeeInOrder(['Stok', '5 kg', 'Minimum', '2 kg', 'Pakai / Hari', '0,1 kg', 'Cukup', '50 hari'])
            ->assertSeeInOrder(['Mutasi Terakhir', '+2 kg', '-0,7 kg'])
            ->assertSee('href="'.route('cabang.stok.riwayat', $mie).'"', false)
            ->assertSee('Minta Barang');

        $this->actingAs($this->admin)
            ->get(route('cabang.stok.intip', $this->bahan['Wijen']))
            ->assertOk()
            ->assertSee('Stok sistem minus. Cek dengan stock opname.')
            ->assertSee('Belum ada pergerakan.');
    }

    public function test_intip_tanpa_minta_barang_bila_catat_permintaan_dimatikan(): void
    {
        app(\App\Services\KontrolSistemService::class)->aturAkses(User::ROLE_ADMIN_CABANG, [
            'opname' => ['lihat' => true, 'catat' => true],
            'permintaan' => ['catat' => false],
        ], $this->adminUtama);

        $this->actingAs($this->admin)
            ->get(route('cabang.stok.intip', $this->bahan['Shoyu']))
            ->assertOk()
            ->assertDontSee('Minta Barang');
    }

    public function test_admin_utama_dan_pusat_mengintip_bahan_di_lokasinya(): void
    {
        $nori = $this->bahan['Nori'];

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok', ['lokasi' => $this->cabang->id]))
            ->assertSee('href="'.route('admin-utama.stok.intip', [$nori, 'lokasi' => $this->cabang->id]).'"', false);

        $this->app->forgetScopedInstances();

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok.intip', [$nori, 'lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('9 kg')
            ->assertSee('href="'.route('admin-utama.stok.riwayat', [$nori, 'lokasi' => $this->pusat->id]).'"', false)
            ->assertDontSee('Minta Barang');

        $this->app->forgetScopedInstances();

        $this->actingAs(User::factory()->adminPusat($this->pusat->id)->create())
            ->get(route('pusat.stok.intip', $nori))
            ->assertOk()
            ->assertSee('9 kg')
            ->assertSee('Gudang Pusat');
    }

    public function test_dashboard_cabang_segera_habis_menaut_ke_riwayat_bahan(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSee('class="daftar-ringkas"', false)
            ->assertSee(route('cabang.stok.riwayat', $this->bahan['Miso']), false)
            ->assertSee('Lihat semua stok');
    }

    public function test_dashboard_admin_utama_melipat_peringatan_lebih_dari_lima(): void
    {
        foreach (range(1, 5) as $i) {
            Cabang::create(['kode' => 'C'.$i, 'nama' => 'Cabang '.$i, 'jenis' => Cabang::JENIS_CABANG])
                ->forceFill(['created_at' => now()->subDays(10)])->save();
        }

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSee('<details class="peringatan-lain">', false)
            ->assertSeeInOrder(['Ringkasan per Lokasi', 'Cabang 1', 'Belum Dicatat']);
    }

    public function test_ringkasan_lokasi_menyebut_penjualan_belum_dicatat(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Ringkasan per Lokasi', 'Cabang Jati', 'Belum Dicatat']);
    }
}
