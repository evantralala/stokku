<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use App\Services\PantauanStokService;
use App\View\MenuSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KerangkaTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $pusat;
    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(20, 0));

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $gram = Satuan::create(['nama' => 'gram']);
        $kategori = Kategori::create(['nama' => 'Kering']);

        // [kode, nama, saldo cabang, minimum cabang]: merah, kuning, hijau
        foreach ([
            ['BB-0001', 'Nori', -20, 500],
            ['BB-0002', 'Shoyu', 300, 500],
            ['BB-0003', 'Mie ramen', 1000, 500],
        ] as [$kode, $nama, $saldo, $minimum]) {
            $bahan = BahanBaku::create([
                'nama' => $nama, 'kategori_id' => $kategori->id,
                'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
                'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
            ]);

            DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $bahan->id, 'saldo' => $saldo, 'diperbarui_pada' => now()]);
            BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $bahan->id, 'minimum' => $minimum]);
        }
    }

    private function lampu(string $lampu, int $angka, string $tautan): array
    {
        return [
            '<a class="lampu-kepala-item lampu-'.$lampu.'" href="'.$tautan.'">',
            '<span class="lampu-kepala-angka num">'.$angka.'</span>',
        ];
    }

    public function test_dashboard_cabang_dan_pusat_menampilkan_angka_lampu_di_kartu_kondisi_stok(): void
    {
        $admin = User::factory()->adminCabang($this->cabang->id)->create();

        $this->actingAs($admin)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['<section class="panel grafik-kartu donat-kartu">', '<nav class="lampu-kepala" aria-label="Lampu stok">'], false)
            ->assertSee('<section class="sapaan">', false)
            ->assertSee('Pergerakan Stok')->assertSee('Penjualan')->assertSee('Bulan Lalu')
            ->assertSeeInOrder([
                ...$this->lampu(PantauanStokService::LAMPU_MERAH, 1, route('cabang.stok', ['lampu' => PantauanStokService::LAMPU_MERAH])),
                ...$this->lampu(PantauanStokService::LAMPU_KUNING, 1, route('cabang.stok', ['lampu' => PantauanStokService::LAMPU_KUNING])),
                ...$this->lampu(PantauanStokService::LAMPU_HIJAU, 1, route('cabang.stok', ['lampu' => PantauanStokService::LAMPU_HIJAU])),
            ], false);

        $adminPusat = User::factory()->adminPusat($this->pusat->id)->create();

        $this->actingAs($adminPusat)
            ->get(route('pusat.dashboard'))
            ->assertOk()
            ->assertSeeInOrder($this->lampu(PantauanStokService::LAMPU_MERAH, 3, route('pusat.stok', ['lampu' => PantauanStokService::LAMPU_MERAH])), false);
    }

    public function test_dashboard_admin_utama_menjumlahkan_lampu_semua_lokasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                ...$this->lampu(PantauanStokService::LAMPU_MERAH, 4, '#ringkasan-lokasi'),
                ...$this->lampu(PantauanStokService::LAMPU_KUNING, 1, '#ringkasan-lokasi'),
                ...$this->lampu(PantauanStokService::LAMPU_HIJAU, 1, '#ringkasan-lokasi'),
                '<section class="panel" id="ringkasan-lokasi">',
            ], false);
    }

    public function test_halaman_lain_tanpa_angka_lampu_dan_kepala_menyebut_lokasi(): void
    {
        $admin = User::factory()->adminCabang($this->cabang->id)->create();

        $this->actingAs($admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('<div class="panel alat-stok" data-saring-stok="daftar-stok"', false)
            ->assertDontSee('class="lampu-kepala"', false)
            ->assertSee('<span class="kartu-pengguna-peran">Cabang Jati</span>', false);
    }

    public function test_kartu_pengguna_menyebut_peran_dan_crumb_di_baris_halaman(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.satuan.tambah'))
            ->assertOk()
            ->assertSee('<div class="crumb">', false)
            ->assertSee('<span class="kartu-pengguna-peran">Admin Utama</span>', false);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertDontSee('<div class="crumb">', false);
    }

    public function test_sidebar_bisa_diciutkan_dan_diingat_per_pengguna(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSee('data-kunci-sidebar="stokku:sidebar-ciut:'.$this->adminUtama->id.'"', false)
            ->assertSee('data-ciutkan-sidebar aria-pressed="false" hidden', false)
            ->assertSee('<span class="nav-teks">Ciutkan Menu</span>', false)
            ->assertSee('<span class="nav-teks">Persetujuan</span>', false)
            ->assertDontSee('class="tombol-menu"', false);
    }

    public function test_navigasi_bawah_berisi_empat_menu_utama_per_peran(): void
    {
        $cabang = User::factory()->adminCabang($this->cabang->id)->create();
        $pusat = User::factory()->adminPusat($this->pusat->id)->create();

        $rute = fn (User $user) => array_column(MenuSidebar::utama($user), 'route');

        $this->actingAs($this->adminUtama);
        $this->assertSame(['admin-utama.dashboard', 'admin-utama.persetujuan', 'admin-utama.stok', 'admin-utama.laporan'], $rute($this->adminUtama));
        $this->assertSame(['pusat.dashboard', 'pusat.kiriman', 'pusat.barang-masuk', 'pusat.stok'], $rute($pusat));
        $this->assertSame(['cabang.dashboard', 'cabang.penjualan', 'cabang.terima-barang', 'cabang.stok'], $rute($cabang));

        $this->actingAs($cabang)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['<nav class="navbawah"', '<span>Beranda</span>', '<span>Penjualan</span>', '<span>Terima</span>', '<span>Stok</span>', '<span>Menu</span>'], false);
    }

    public function test_kepala_punya_kotak_cari_bahan_menuju_stok_peran_itu(): void
    {
        $cabang = User::factory()->adminCabang($this->cabang->id)->create();

        foreach ([
            [$this->adminUtama, 'admin-utama.persetujuan', 'admin-utama.dashboard', 'admin-utama.stok'],
            [$cabang, 'cabang.penjualan', 'cabang.dashboard', 'cabang.stok'],
        ] as [$user, $halaman, $dasbor, $tujuan]) {
            $this->app->forgetScopedInstances();

            $this->actingAs($user)
                ->get(route($halaman))
                ->assertOk()
                ->assertSee('<form class="cari-global" role="search" method="GET" action="'.route($tujuan).'">', false)
                ->assertSee('data-cari-global', false);

            $this->app->forgetScopedInstances();

            $this->actingAs($user)
                ->get(route($dasbor))
                ->assertOk()
                ->assertDontSee('class="cari-global"', false)
                ->assertSee('<form class="sapaan-cari" role="search" method="GET" action="'.route($tujuan).'">', false);
        }

        $this->app->forgetScopedInstances();

        $this->actingAs($cabang)
            ->get(route('cabang.stok', ['cari' => 'nori']))
            ->assertOk()
            ->assertDontSee('class="cari-global"', false)
            ->assertSee('data-cari-utama', false)
            ->assertSee('Nori')
            ->assertDontSee('Shoyu');
    }

    public function test_kartu_pengguna_di_bilah_atas_dan_tombol_keluar_di_kaki_sidebar(): void
    {
        $cabang = User::factory()->adminCabang($this->cabang->id)->create(['nama' => 'Rani Kasir']);

        $this->actingAs($cabang)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSee('<div class="brand-sub">'.$cabang->label_role.'</div>', false)
            ->assertSeeInOrder(['<a class="kartu-pengguna" href="'.route('akun').'"', '<span class="kartu-pengguna-nama">Rani Kasir</span>', '<span class="kartu-pengguna-peran">Cabang Jati</span>'], false)
            ->assertSeeInOrder(['<div class="sidebar-kaki">', 'Ciutkan Menu', 'Keluar'], false);
    }
}
