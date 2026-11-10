<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Semua tombol/tautan tambah di data master membuka form yang sama sebagai popup, dengan ukuran yang ditentukan form-nya. */
class TambahKonsistenTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER = [
        'admin-utama.satuan'        => 'pendek',
        'admin-utama.kategori'      => 'pendek',
        'admin-utama.kategori-menu' => 'pendek',
        'admin-utama.lokasi'        => 'panjang',
        'admin-utama.bahan-baku'    => 'panjang',
        'admin-utama.menu'          => 'panjang',
        'admin-utama.pengguna'      => 'panjang',
    ];

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
    }

    /** @return list<string> tag <a> yang menuju alamat ini */
    private function tautanKe(string $html, string $alamat): array
    {
        preg_match_all('#<a\b[^>]*href="'.preg_quote($alamat, '#').'"[^>]*>#', $html, $cocok);

        return $cocok[0];
    }

    public function test_semua_tautan_tambah_di_halaman_master_membuka_popup(): void
    {
        foreach (array_keys(self::MASTER) as $rute) {
            $html = $this->actingAs($this->adminUtama)->get(route($rute))->assertOk()->getContent();
            $tautan = $this->tautanKe($html, route($rute.'.tambah'));

            $this->assertNotEmpty($tautan, $rute);

            foreach ($tautan as $a) {
                $this->assertStringContainsString('data-popup', $a, $rute.': '.$a);
            }
        }
    }

    public function test_daftar_kosong_punya_tautan_tambah_popup(): void
    {
        $html = $this->actingAs($this->adminUtama)->get(route('admin-utama.satuan'))->assertOk()->getContent();

        $this->assertStringContainsString('Belum ada satuan.', $html);
        $this->assertCount(2, $this->tautanKe($html, route('admin-utama.satuan.tambah')));
    }

    public function test_form_master_menentukan_ukuran_popup_sendiri(): void
    {
        foreach (self::MASTER as $rute => $ukuran) {
            $this->actingAs($this->adminUtama)->get(route($rute.'.tambah'))
                ->assertOk()
                ->assertSee('data-popup-isi data-popup-ukuran="'.$ukuran.'"', false);
        }
    }

    public function test_tautan_tambah_lintas_halaman_juga_popup(): void
    {
        $html = $this->actingAs($this->adminUtama)->get(route('admin-utama.bahan-baku.tambah'))->assertOk()->getContent();

        foreach (['admin-utama.kategori.tambah', 'admin-utama.satuan.tambah'] as $rute) {
            foreach ($this->tautanKe($html, route($rute)) as $a) {
                $this->assertStringContainsString('data-popup', $a);
            }
        }

        $html = $this->actingAs($this->adminUtama)->get(route('admin-utama.stok-minimum'))->assertOk()->getContent();
        $tautan = $this->tautanKe($html, route('admin-utama.lokasi.tambah'));
        $this->assertNotEmpty($tautan);
        $this->assertStringContainsString('data-popup', $tautan[0]);
    }
}
