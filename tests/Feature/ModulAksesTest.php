<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangMasukDetail;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\LogPerubahan;
use App\Models\Opname;
use App\Models\Satuan;
use App\Models\User;
use App\Services\KontrolSistemService;
use App\Support\DaftarKontrol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulAksesTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $cabang = Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($cabang->id)->create();
    }

    private function kontrol(): KontrolSistemService
    {
        return app(KontrolSistemService::class);
    }

    /** Isian form hak akses dengan semua aksi yang bisa diatur dinyalakan, lalu ditimpa $ubah. */
    private function isianAkses(string $role, array $ubah = []): array
    {
        $isian = [];

        foreach (DaftarKontrol::MENU[$role] as $menu => $definisi) {
            foreach (DaftarKontrol::bisaDiatur($definisi) as $aksi) {
                $isian[$menu][$aksi] = 1;
            }
        }

        return ['akses' => array_replace_recursive($isian, $ubah)];
    }

    public function test_aksi_dikenali_dari_nama_route_dan_metode(): void
    {
        $this->assertSame(['opname', 'lihat'], DaftarKontrol::aksiRute(User::ROLE_ADMIN_CABANG, 'cabang.opname.lihat', 'GET'));
        $this->assertSame(['opname', 'catat'], DaftarKontrol::aksiRute(User::ROLE_ADMIN_CABANG, 'cabang.opname.mulai', 'POST'));
        $this->assertSame(['permintaan', 'catat'], DaftarKontrol::aksiRute(User::ROLE_ADMIN_CABANG, 'cabang.permintaan.buat', 'GET'));
        $this->assertSame(['stok', 'lihat'], DaftarKontrol::aksiRute(User::ROLE_ADMIN_PUSAT, 'pusat.dashboard', 'GET'));
        $this->assertNull(DaftarKontrol::aksiRute(User::ROLE_ADMIN_CABANG, 'notifikasi', 'GET'));
    }

    public function test_halaman_kontrol_sistem_menampilkan_modul_dan_hak_akses(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.pengaturan'))
            ->assertOk()
            ->assertSee('Modul')
            ->assertSee('Permintaan Otomatis')
            ->assertSee('Hak Akses')
            ->assertSee(route('admin-utama.kontrol-sistem.akses', 'cabang'), false);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.kontrol-sistem.akses', 'cabang'))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('Dikunci')
            ->assertSee('name="akses[opname][catat]"', false);

        $this->actingAs($this->adminUtama)->get('/admin-utama/kontrol-sistem/akses/utama')->assertNotFound();
    }

    public function test_modul_peringatan_dini_dimatikan_panelnya_hilang_dan_web_order_tidak_ada_lagi(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSee('id="peringatan-dini"', false);

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.kontrol-sistem.modul', DaftarKontrol::MODUL_PERINGATAN_DINI), ['nyala' => 0])
            ->assertSessionHas('sukses', 'Modul Peringatan dimatikan.');

        $this->assertFalse($this->kontrol()->modulNyala(DaftarKontrol::MODUL_PERINGATAN_DINI));
        $this->assertSame('modul.mati', LogPerubahan::sole()->aksi);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertDontSee('id="peringatan-dini"', false)
            ->assertDontSee('Web Order');

        $this->actingAs($this->adminUtama)->patch('/admin-utama/kontrol-sistem/modul/web_order', ['nyala' => 0])->assertNotFound();
        $this->actingAs($this->adminUtama)->get('/admin-utama/penjualan-masuk')->assertNotFound();
        $this->postJson('/api/v1/penjualan', [])->assertNotFound();
    }

    public function test_fitur_kedaluwarsa_sudah_dihapus_seluruhnya(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.laporan'))->assertOk()->assertDontSee('Kedaluwarsa');
        $this->actingAs($this->adminUtama)->get('/admin-utama/laporan/kedaluwarsa')->assertNotFound();
        $this->actingAs($this->adminUtama)->patch('/admin-utama/kontrol-sistem/modul/kedaluwarsa', ['nyala' => 0])->assertNotFound();
        $this->actingAs($this->adminUtama)->get(route('admin-utama.pengaturan'))->assertOk()->assertDontSee('Kedaluwarsa');
        $this->assertArrayNotHasKey('stok:cek-kedaluwarsa', \Illuminate\Support\Facades\Artisan::all());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('stok_batch'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('bahan_baku', 'lacak_kedaluwarsa'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('stok_mutasi', 'tanggal_kedaluwarsa'));

        $gram = Satuan::create(['nama' => 'gram']);
        $susu = BahanBaku::create([
            'nama' => 'Susu', 'kategori_id' => Kategori::create(['nama' => 'Minuman'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id, 'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);

        $this->actingAs($this->adminPusat)->get(route('pusat.barang-masuk.catat'))->assertOk()->assertDontSee('kedaluwarsa');
        $this->post(route('pusat.barang-masuk.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $susu->id, 'qty' => '10', 'tanggal_kedaluwarsa' => now()->addDays(3)->toDateString()]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10.0, (float) BarangMasukDetail::sole()->qty);
    }

    public function test_modul_peringatan_dini_dimatikan_panelnya_hilang(): void
    {
        $this->kontrol()->aturModul(DaftarKontrol::MODUL_PERINGATAN_DINI, false, $this->adminUtama);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertDontSee('id="peringatan-dini"', false);
    }

    public function test_jadwal_otomatis_dilewati_saat_modulnya_mati(): void
    {
        foreach ([DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS, DaftarKontrol::MODUL_TOLAK_OTOMATIS] as $modul) {
            $this->kontrol()->aturModul($modul, false, $this->adminUtama);
        }

        $this->artisan('permintaan:buat-otomatis --paksa')->expectsOutputToContain('dimatikan')->assertSuccessful();
        $this->artisan('persetujuan:tolak-otomatis')->expectsOutputToContain('dimatikan')->assertSuccessful();
    }

    public function test_hak_akses_opname_cabang_bisa_dimatikan_tanpa_mengganggu_pusat(): void
    {
        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.kontrol-sistem.akses.simpan', 'cabang'), $this->isianAkses(User::ROLE_ADMIN_CABANG, ['opname' => ['lihat' => 0]]))
            ->assertSessionHas('sukses', 'Hak akses Admin Cabang disimpan.');

        $log = LogPerubahan::sole();
        $this->assertSame('akses.ubah', $log->aksi);
        $this->assertSame([
            ['label' => 'Opname · Lihat', 'lama' => 'Boleh', 'baru' => 'Tidak'],
            ['label' => 'Opname · Catat', 'lama' => 'Boleh', 'baru' => 'Tidak'],
        ], $log->rincian);

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.opname'))
            ->assertRedirect(route('cabang.dashboard'))
            ->assertSessionHas('galat');

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertDontSee('Stock Opname');

        $this->actingAs($this->adminPusat)->get(route('pusat.opname'))->assertOk();
    }

    public function test_catat_opname_dimatikan_lihat_tetap_boleh(): void
    {
        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.kontrol-sistem.akses.simpan', 'pusat'), $this->isianAkses(User::ROLE_ADMIN_PUSAT, ['opname' => ['catat' => 0]]))
            ->assertSessionHas('sukses');

        $this->actingAs($this->adminPusat)
            ->get(route('pusat.opname'))
            ->assertOk()
            ->assertDontSee('Mulai Opname');

        $this->actingAs($this->adminPusat)
            ->from(route('pusat.opname'))
            ->post(route('pusat.opname.mulai'))
            ->assertRedirect(route('pusat.opname'))
            ->assertSessionHas('galat');

        $this->assertSame(0, Opname::count());
    }

    public function test_menu_yang_dikunci_tidak_bisa_dimatikan(): void
    {
        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.kontrol-sistem.akses.simpan', 'cabang'), $this->isianAkses(User::ROLE_ADMIN_CABANG, [
                'penjualan' => ['lihat' => 0, 'catat' => 0], 'barang-keluar' => ['catat' => 0], 'terima-barang' => ['catat' => 0],
            ]))
            ->assertSessionHas('sukses', 'Tidak ada hak akses yang berubah.');

        $this->assertTrue($this->kontrol()->bolehPeran(User::ROLE_ADMIN_CABANG, 'penjualan', 'lihat'));
        $this->assertTrue($this->kontrol()->bolehPeran(User::ROLE_ADMIN_CABANG, 'barang-keluar', 'catat'));
        $this->assertSame(0, LogPerubahan::count());
    }

    public function test_catat_permintaan_hanya_bisa_mati_selama_permintaan_otomatis_menyala(): void
    {
        $this->kontrol()->aturModul(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS, false, $this->adminUtama);
        $tanpaPermintaan = $this->isianAkses(User::ROLE_ADMIN_CABANG, ['permintaan' => ['catat' => 0]]);

        $this->actingAs($this->adminUtama)
            ->from(route('admin-utama.pengaturan'))
            ->put(route('admin-utama.kontrol-sistem.akses.simpan', 'cabang'), $tanpaPermintaan)
            ->assertRedirect(route('admin-utama.pengaturan'))
            ->assertSessionHasErrors('akses');

        $this->assertTrue($this->kontrol()->bolehPeran(User::ROLE_ADMIN_CABANG, 'permintaan', 'catat'));

        $this->kontrol()->aturModul(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS, true, $this->adminUtama);

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.kontrol-sistem.akses.simpan', 'cabang'), $tanpaPermintaan)
            ->assertSessionHasNoErrors();

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.kontrol-sistem.modul', DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS), ['nyala' => 0])
            ->assertSessionHas('galat');
        $this->assertTrue($this->kontrol()->modulNyala(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS));

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.permintaan.buat'))
            ->assertRedirect(route('cabang.dashboard'));

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.permintaan'))
            ->assertOk()
            ->assertDontSee(route('cabang.permintaan.buat'), false);
    }
}
