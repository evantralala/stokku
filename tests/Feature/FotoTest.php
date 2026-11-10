<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\LogPerubahan;
use App\Models\Menu;
use App\Models\Satuan;
use App\Models\User;
use App\Services\FotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Kategori $kategori;
    private Satuan $gram;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FotoService::DISK);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->kategori = Kategori::create(['nama' => 'Sayur']);
        $this->gram = Satuan::create(['nama' => 'gram']);
    }

    private function dataBahan(array $ubah = []): array
    {
        return $ubah + [
            'nama'                => 'Daun bawang',
            'kategori_id'         => $this->kategori->id,
            'satuan_dasar_id'     => $this->gram->id,
            'satuan_beli_id'      => $this->gram->id,
            'isi_per_satuan_beli' => '1',
            'toleransi_persen'    => '5',
        ];
    }

    private function bahan(): BahanBaku
    {
        return BahanBaku::query()->where('nama', 'Daun bawang')->firstOrFail();
    }

    public function test_foto_bahan_diperkecil_dan_dibaca_lewat_aplikasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataBahan([
                'foto' => UploadedFile::fake()->image('daun.jpg', 2400, 1600),
            ]))
            ->assertSessionHasNoErrors();

        $bahan = $this->bahan();
        $this->assertStringStartsWith(FotoService::FOLDER_BAHAN.'/', $bahan->foto);
        Storage::disk(FotoService::DISK)->assertExists($bahan->foto);

        [$lebar, $tinggi] = getimagesizefromstring(Storage::disk(FotoService::DISK)->get($bahan->foto));
        $this->assertSame(FotoService::SISI_MAKS, $lebar);
        $this->assertLessThan($lebar, $tinggi);

        $this->get(route('admin-utama.bahan-baku'))
            ->assertOk()
            ->assertSee($bahan->urlFoto(), false);

        $this->get($bahan->urlFoto())->assertOk();

        auth()->logout();
        $this->get($bahan->urlFoto())->assertRedirect(route('login'));
    }

    public function test_ganti_dan_hapus_foto_membuang_file_lama(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataBahan(['foto' => UploadedFile::fake()->image('a.png', 300, 300)]));
        $lama = $this->bahan()->foto;

        $this->put(route('admin-utama.bahan-baku.perbarui', $this->bahan()), $this->dataBahan(['foto' => UploadedFile::fake()->image('b.jpg', 300, 300)]))
            ->assertSessionHasNoErrors();
        $baru = $this->bahan()->foto;

        $this->assertNotSame($lama, $baru);
        Storage::disk(FotoService::DISK)->assertMissing($lama);
        Storage::disk(FotoService::DISK)->assertExists($baru);

        $log = LogPerubahan::query()->latest('id')->first();
        $this->assertContains(['label' => 'Foto', 'lama' => 'Ada', 'baru' => 'Diganti'], $log->rincian);

        $this->put(route('admin-utama.bahan-baku.perbarui', $this->bahan()), $this->dataBahan())
            ->assertSessionHasNoErrors();
        $this->assertSame($baru, $this->bahan()->foto);

        $this->put(route('admin-utama.bahan-baku.perbarui', $this->bahan()), $this->dataBahan(['hapus_foto' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertNull($this->bahan()->foto);
        Storage::disk(FotoService::DISK)->assertMissing($baru);
    }

    public function test_bahan_yang_dihapus_ikut_membuang_fotonya(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataBahan(['foto' => UploadedFile::fake()->image('a.jpg', 200, 200)]));
        $foto = $this->bahan()->foto;

        $this->delete(route('admin-utama.bahan-baku.hapus', $this->bahan()))->assertSessionHasNoErrors();

        Storage::disk(FotoService::DISK)->assertMissing($foto);
    }

    public function test_file_bukan_gambar_atau_terlalu_besar_ditolak(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.bahan-baku.simpan'), $this->dataBahan(['foto' => UploadedFile::fake()->create('daftar.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors('foto');

        $this->post(route('admin-utama.bahan-baku.simpan'), $this->dataBahan(['foto' => UploadedFile::fake()->image('besar.jpg')->size(FotoService::MAKS_KB + 1)]))
            ->assertSessionHasErrors('foto');

        $this->assertDatabaseMissing('bahan_baku', ['nama' => 'Daun bawang']);
    }

    public function test_foto_menu_tampil_di_daftar_menu_dan_form_penjualan(): void
    {
        $kategori = KategoriMenu::create(['nama' => 'Ramen']);
        $menu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $kategori->id]);

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.menu.perbarui', $menu), [
                'nama' => 'Shoyu Ramen', 'kategori_menu_id' => $kategori->id,
                'foto' => UploadedFile::fake()->image('ramen.jpg', 640, 480),
            ])
            ->assertSessionHasNoErrors();

        $menu->refresh();
        $this->assertStringStartsWith(FotoService::FOLDER_MENU.'/', $menu->foto);

        $this->get(route('admin-utama.menu'))->assertSee($menu->urlFoto(), false);

        $cabang = Cabang::create(['kode' => 'MLT', 'nama' => 'Cabang Melati', 'jenis' => Cabang::JENIS_CABANG]);
        $kasir = User::factory()->adminCabang($cabang->id)->create();

        $this->actingAs($kasir)->get(route('cabang.penjualan.catat'))
            ->assertOk()
            ->assertSee($menu->urlFoto(), false);
        $this->get($menu->urlFoto())->assertOk();
    }

    public function test_tanpa_foto_tampil_huruf_awal_dan_alamat_salah_ditolak(): void
    {
        $this->actingAs($this->adminUtama)->post(route('admin-utama.bahan-baku.simpan'), $this->dataBahan());

        $this->get(route('admin-utama.bahan-baku'))
            ->assertSee('foto-kosong', false)
            ->assertSee('<span class="foto-awal" aria-hidden="true">DB</span>', false);

        $this->get('/foto/bahan/tidakada.webp')->assertNotFound();
        $this->get('/foto/lain/abc.webp')->assertNotFound();
        $this->get('/foto/bahan/..%2F.env')->assertNotFound();
    }
}
