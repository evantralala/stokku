<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Transisi geser antarhalaman dan popup. */
class TransisiTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_memasang_arah_transisi_dan_gaya_geser(): void
    {
        $this->actingAs(User::factory()->adminUtama()->create())
            ->get(route('admin-utama.persetujuan'))
            ->assertOk()
            ->assertSee("window.addEventListener('pageswap'", false)
            ->assertSee("window.addEventListener('pagereveal'", false)
            ->assertDontSee('Apa Itu');

        $css = file_get_contents(resource_path('css/transisi.css'));
        $this->assertStringContainsString('@view-transition', $css);
        $this->assertStringContainsString('view-transition-name: isi', $css);
        $this->assertStringContainsString('active-view-transition-type(mundur)', $css);
        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $css);
        $this->assertStringContainsString("@import './transisi.css';", file_get_contents(resource_path('css/app.css')));
        $this->assertStringContainsString('@view-transition', file_get_contents(resource_path('css/login.css')));
        $this->assertStringContainsString('tungguAnimasiTutup', file_get_contents(resource_path('js/popup.js')));
    }

    /** Penanda PWA tidak boleh sama dengan penanda "sudah terpasang" milik skrip lain, supaya tidak ikut disembunyikan. */
    public function test_penanda_pwa_tidak_bentrok_dengan_penanda_skrip(): void
    {
        $pwa = file_get_contents(resource_path('js/pwa.js'));
        $this->assertStringNotContainsString("'[data-terpasang]'", $pwa);
        $this->assertStringContainsString("'[data-aplikasi-terpasang]'", $pwa);

        $this->actingAs(User::factory()->adminUtama()->create())
            ->get(route('akun'))
            ->assertOk()
            ->assertSee('data-aplikasi-terpasang hidden', false)
            ->assertDontSee(' data-terpasang', false);
    }

    public function test_tombol_resep_di_daftar_menu_membuka_halaman_biasa(): void
    {
        $menu = \App\Models\Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => \App\Models\KategoriMenu::create(['nama' => 'Ramen'])->id]);
        $html = $this->actingAs(User::factory()->adminUtama()->create())->get(route('admin-utama.menu'))->assertOk()->getContent();

        preg_match('#<a [^>]*aria-label="Resep Shoyu Ramen"[^>]*>#', $html, $tombol);
        $this->assertNotEmpty($tombol);
        $this->assertStringContainsString(route('admin-utama.menu.resep', $menu), $tombol[0]);
        $this->assertStringNotContainsString('data-popup', $tombol[0]);
    }
}
