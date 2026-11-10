<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use App\Services\FotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Foto profil: tiap akun mengatur fotonya sendiri di menu Akun; tanpa foto tetap inisial. */
class FotoProfilTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FotoService::DISK);
        $this->withoutVite();
        $this->admin = User::factory()->adminUtama()->create(['nama' => 'Admin Uji']);
    }

    private function unggah(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->from(route('akun'))->put(route('akun.foto'), $data);
    }

    public function test_tanpa_foto_tampil_inisial_dan_panel_foto_ada(): void
    {
        $this->actingAs($this->admin)->get(route('akun'))->assertOk()
            ->assertSee('Foto Profil')
            ->assertSee('>AU<', false)
            ->assertSee(route('akun.foto'), false)
            ->assertDontSee('ava-foto');
    }

    public function test_foto_dipotong_persegi_dan_tampil_di_topbar_dan_akun(): void
    {
        $this->unggah(['foto' => UploadedFile::fake()->image('pp.jpg', 1200, 800)])
            ->assertRedirect(route('akun').'#foto-profil')->assertSessionHas('sukses', 'Foto profil disimpan.');

        $path = $this->admin->fresh()->foto;
        $this->assertStringStartsWith(FotoService::FOLDER_PROFIL.'/', $path);
        Storage::disk(FotoService::DISK)->assertExists($path);
        $this->assertSame([320, 320], array_slice(getimagesizefromstring(Storage::disk(FotoService::DISK)->get($path)), 0, 2));

        $url = FotoService::url($path);
        $this->get(route('akun'))->assertSee('ava-besar', false)->assertSee('ava-foto', false)->assertSee($url, false);
        $this->get(route('admin-utama.dashboard'))->assertSee('ava-foto', false)->assertSee($url, false);
        $this->get($url)->assertOk();
    }

    public function test_ganti_foto_menghapus_file_lama_lalu_hapus_kembali_ke_inisial(): void
    {
        $this->unggah(['foto' => UploadedFile::fake()->image('a.png', 400, 400)]);
        $lama = $this->admin->fresh()->foto;

        $this->unggah(['foto' => UploadedFile::fake()->image('b.png', 400, 400)]);
        $baru = $this->admin->fresh()->foto;
        $this->assertNotSame($lama, $baru);
        Storage::disk(FotoService::DISK)->assertMissing($lama);

        $this->unggah(['hapus_foto' => '1'])->assertSessionHas('sukses', 'Foto profil dihapus.');
        $this->assertNull($this->admin->fresh()->foto);
        Storage::disk(FotoService::DISK)->assertMissing($baru);
        $this->get(route('akun'))->assertSee('>AU<', false);

        $this->unggah([])->assertSessionHas('sukses', 'Tidak ada yang berubah.');
    }

    public function test_hanya_gambar_dan_hanya_akun_sendiri(): void
    {
        $this->unggah(['foto' => UploadedFile::fake()->create('pp.pdf', 10, 'application/pdf')])->assertSessionHasErrors('foto');
        $this->assertNull($this->admin->fresh()->foto);

        $cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $kasir = User::factory()->adminCabang($cabang->id)->create();
        $this->actingAs($kasir)->put(route('akun.foto'), ['foto' => UploadedFile::fake()->image('k.jpg', 300, 300)]);
        $this->assertNotNull($kasir->fresh()->foto);
        $this->assertNull($this->admin->fresh()->foto);

        auth()->logout();
        $this->put(route('akun.foto'), ['foto' => UploadedFile::fake()->image('x.jpg', 300, 300)])->assertRedirect(route('login'));
    }

    public function test_dikosongkan_bersama_foto_lain_saat_migrate_fresh(): void
    {
        $this->unggah(['foto' => UploadedFile::fake()->image('pp.jpg', 300, 300)]);

        $this->assertSame(1, app(FotoService::class)->hapusSemua());
    }
}
