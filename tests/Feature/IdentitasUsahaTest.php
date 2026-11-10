<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use App\Services\IdentitasUsahaService;
use App\Support\BukuExcel;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Identitas usaha (nama dan logo) diubah dari Pengaturan, tampil di aplikasi, halaman masuk, manifest HP, dan Excel. */
class IdentitasUsahaTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminJati;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(PengaturanSeeder::class);
        $jati = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminJati = User::factory()->adminCabang($jati->id)->create();
    }

    private function simpan(array $isian = [])
    {
        return $this->actingAs($this->adminUtama)->put(route('admin-utama.pengaturan.identitas'), $isian + [
            'nama_usaha' => 'Sate Pak Budi', 'nama_aplikasi' => 'Stok Sate Budi', 'nama_singkat' => 'SateBudi', 'kota' => 'Klaten',
        ]);
    }

    private function logoPng(int $lebar = 300, int $tinggi = 150): UploadedFile
    {
        $gambar = imagecreatetruecolor($lebar, $tinggi);
        imagefill($gambar, 0, 0, imagecolorallocate($gambar, 200, 30, 30));
        ob_start();
        imagepng($gambar);

        return UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());
    }

    public function test_bawaan_tetap_stokku_sebelum_diubah(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('Masuk — Stokku', false)
            ->assertSee('Dapur Contoh')
            ->assertSee(asset('img/logo-stokku.png'));

        $this->actingAs($this->adminUtama)->get(route('admin-utama.pengaturan'))->assertOk()
            ->assertSee('Identitas Usaha')
            ->assertSee('value="Stokku"', false)
            ->assertDontSee('Pakai Logo Bawaan');
    }

    public function test_nama_baru_tampil_di_aplikasi_halaman_masuk_manifest_dan_excel(): void
    {
        $this->simpan()->assertRedirect(route('admin-utama.pengaturan').'#identitas')->assertSessionHas('sukses', 'Identitas usaha disimpan.');

        $this->actingAs($this->adminJati)->get(route('akun'))->assertOk()
            ->assertSee('<div class="brand-word">Stok Sate Budi</div>', false)
            ->assertSee('— Stok Sate Budi</title>', false)
            ->assertSee('content="SateBudi"', false)
            ->assertDontSee('Stokku');

        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee('Sate Pak Budi')->assertSee('Klaten')->assertDontSee('Stokku');

        $manifest = $this->get(route('manifest'))->assertOk()->json();
        $this->assertSame('Stok Sate Budi', $manifest['name']);
        $this->assertSame('SateBudi', $manifest['short_name']);

        $this->assertSame('Stok Sate Budi', BukuExcel::baru('Uji')->getProperties()->getCreator());
        $this->assertDatabaseHas('log_perubahan', ['aksi' => 'identitas.ubah', 'user_id' => $this->adminUtama->id]);
    }

    public function test_simpan_tanpa_perubahan_tidak_mencatat_log(): void
    {
        $this->simpan(['nama_usaha' => 'Dapur Contoh', 'nama_aplikasi' => 'Stokku', 'nama_singkat' => 'Stokku', 'kota' => 'Jakarta'])
            ->assertSessionHas('sukses', 'Tidak ada yang berubah.');
        $this->assertDatabaseMissing('log_perubahan', ['aksi' => 'identitas.ubah']);
    }

    public function test_kota_boleh_kosong_dan_isian_wajib_divalidasi(): void
    {
        $this->simpan(['kota' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', app(IdentitasUsahaService::class)->data()['kota']);
        $this->get(route('login'))->assertDontSee('&middot; Jakarta', false);

        $this->simpan(['nama_usaha' => '', 'nama_singkat' => 'Nama Yang Terlalu Panjang'])->assertSessionHasErrors(['nama_usaha', 'nama_singkat']);
        $this->simpan(['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
    }

    public function test_logo_diolah_jadi_logo_dan_ikon_hp_lalu_bisa_kembali_ke_bawaan(): void
    {
        $this->simpan(['logo' => $this->logoPng()])->assertSessionHasNoErrors();

        $disk = Storage::disk('public');
        foreach (array_keys(IdentitasUsahaService::BERKAS) as $berkas) {
            $disk->assertExists('identitas/'.$berkas);
        }
        $this->assertSame([192, 192], array_slice(getimagesize($disk->path('identitas/ikon-192.png')), 0, 2));
        $this->assertSame([300, 150], array_slice(getimagesize($disk->path('identitas/logo.png')), 0, 2));
        $this->assertSame([96, 48], array_slice(getimagesize($disk->path('identitas/logo-kecil.png')), 0, 2));

        $url = app(IdentitasUsahaService::class)->url('logo.png');
        $this->assertStringContainsString('/identitas/logo.png?v=', $url);
        auth()->logout();
        $this->get(route('login'))->assertSee($url, false);
        $this->get(route('identitas.berkas', 'ikon-512.png'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('/identitas/ikon-192.png', $this->get(route('manifest'))->json('icons.0.src'));

        $this->simpan(['logo_bawaan' => '1'])->assertSessionHasNoErrors();
        $disk->assertMissing('identitas/logo.png');
        $this->assertSame(asset('img/logo-stokku.png'), app(IdentitasUsahaService::class)->url('logo.png'));
        $this->get(route('identitas.berkas', 'logo.png'))->assertNotFound();
    }

    public function test_hanya_admin_utama_yang_bisa_mengubah(): void
    {
        $this->actingAs($this->adminJati)->put(route('admin-utama.pengaturan.identitas'), [
            'nama_usaha' => 'X', 'nama_aplikasi' => 'X', 'nama_singkat' => 'X',
        ])->assertForbidden();
        $this->get(route('identitas.berkas', 'lain.png'))->assertNotFound();
    }
}
