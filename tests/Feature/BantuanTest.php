<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use App\Services\BantuanService;
use App\Services\KontrolSistemService;
use App\Support\DaftarBantuan;
use App\Support\DaftarKontrol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BantuanTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();

        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();
    }

    private function kontrol(): KontrolSistemService
    {
        return app(KontrolSistemService::class);
    }

    /** @return list<string> */
    private function kunciTopik(User $user): array
    {
        return array_keys(app(BantuanService::class)->untuk($user));
    }

    public function test_tiap_peran_melihat_topik_perannya_dan_topik_umum(): void
    {
        $this->assertSame(
            [...array_keys(DaftarBantuan::TOPIK[User::ROLE_ADMIN_PUSAT]), ...array_keys(DaftarBantuan::UMUM)],
            $this->kunciTopik($this->adminPusat)
        );
        $this->assertContains('pengaturan', $this->kunciTopik($this->adminUtama));
        $this->assertNotContains('pengaturan', $this->kunciTopik($this->adminCabang));

        $this->actingAs($this->adminCabang)
            ->get(route('bantuan'))
            ->assertOk()
            ->assertSee('href="'.route('bantuan').'"', false)
            ->assertSeeInOrder(['Penjualan', 'Terima Barang', 'Opname', 'Arti Lampu Stok', 'Akun'])
            ->assertSee('Buka Terima Barang')
            ->assertDontSee('Kontrol sistem')
            ->assertDontSee('data-popup-isi', false);
    }

    public function test_modul_mati_menyembunyikan_topik_dan_butir_yang_bergantung(): void
    {
        $this->assertNotContains('web-order', $this->kunciTopik($this->adminUtama));
        $butir = 'Bahan di bawah minimum dibuatkan permintaan otomatis setiap hari.';
        $semuaButir = fn () => collect(app(BantuanService::class)->untuk($this->adminUtama))->flatMap(fn ($t) => $t['langkah'] ?? [])->all();
        $this->assertContains($butir, $semuaButir());

        $this->kontrol()->aturModul(DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS, false, $this->adminUtama);

        $this->assertNotContains($butir, $semuaButir());
    }

    public function test_hak_akses_mengatur_topik_dan_butir_cabang(): void
    {
        $topik = app(BantuanService::class)->untuk($this->adminCabang);
        $this->assertSame('Meminta bahan ke Gudang Pusat.', $topik['permintaan']['ringkas']);
        $this->assertArrayNotHasKey('permintaan-otomatis', $topik);
        $this->assertContains('Dari ringkasan bahan, tekan "Minta Barang" untuk membuat permintaan berisi beberapa bahan.', $topik['stok-cabang']['langkah']);

        $this->kontrol()->aturAkses(User::ROLE_ADMIN_CABANG, [
            'permintaan' => [DaftarKontrol::AKSI_LIHAT => 1],
            'opname'     => [],
        ], $this->adminUtama);

        $topik = app(BantuanService::class)->untuk($this->adminCabang);
        $this->assertArrayNotHasKey('permintaan', $topik);
        $this->assertSame('Permintaan dibuat otomatis untuk bahan di bawah minimum.', $topik['permintaan-otomatis']['ringkas']);
        $this->assertArrayNotHasKey('opname', $topik);
        $this->assertNotContains('Dari ringkasan bahan, tekan "Minta Barang" untuk membuat permintaan berisi beberapa bahan.', $topik['stok-cabang']['langkah']);
    }

    public function test_langkah_penjualan_cabang_selalu_catat_sendiri(): void
    {
        $langkah = app(BantuanService::class)->untuk($this->adminCabang)['penjualan']['langkah'];

        $this->assertStringContainsString('Catat Penjualan', $langkah[0]);
        $this->assertCount(4, $langkah);
        $this->assertStringContainsString('Perkiraan Stok Setelah Disimpan', $langkah[1]);
    }

    public function test_tombol_bantuan_di_kepala_halaman_membuka_topik_halaman_itu_sebagai_popup(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('pusat.barang-masuk.catat'))
            ->assertOk()
            ->assertSee('<a class="tombol-bantuan" href="'.route('bantuan', ['topik' => 'barang-masuk']).'#bantuan-barang-masuk"', false)
            ->assertSee('data-popup data-popup-judul="Barang Masuk" aria-label="Bantuan: Barang Masuk"', false);

        $this->actingAs($this->adminPusat)
            ->get(route('bantuan', ['topik' => 'barang-masuk']))
            ->assertOk()
            ->assertSee('id="bantuan-barang-masuk"', false)
            ->assertSeeInOrder(['id="bantuan-barang-masuk"', ' open>', 'class="bantuan-isi" data-popup-isi', 'Tekan "Catat"', 'Semua Bantuan'], false)
            ->assertDontSee('class="tombol-bantuan"', false);

        $this->app->forgetScopedInstances();
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.stok-minimum'))
            ->assertSee('href="'.route('bantuan', ['topik' => 'stok-minimum']).'#bantuan-stok-minimum"', false);
    }

    public function test_cari_menyaring_topik_di_server(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('bantuan', ['cari' => 'OPNAME']))
            ->assertOk()
            ->assertSee('Opname')
            ->assertSee('data-cari-bantuan', false)
            ->assertDontSee('id="bantuan-kiriman"', false);

        $this->actingAs($this->adminPusat)
            ->get(route('bantuan', ['cari' => 'tidak-ada-sama-sekali']))
            ->assertOk()
            ->assertSee('Tidak ada topik yang cocok.')
            ->assertDontSee('<details', false);

        $this->get(route('bantuan'))->assertOk();
        auth()->logout();
        $this->get(route('bantuan'))->assertRedirect(route('login'));
    }
}
