<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LokasiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
    }

    private function buatLokasi(string $jenis, array $data = []): Cabang
    {
        static $nomor = 0;
        $nomor++;

        return Cabang::create($data + [
            'kode' => 'L'.$nomor,
            'nama' => 'Lokasi '.$nomor,
            'jenis' => $jenis,
            'aktif' => true,
        ]);
    }

    private function dataForm(array $ubah = []): array
    {
        return $ubah + [
            'kode' => 'JTI',
            'nama' => 'Cabang Jati',
            'jenis' => Cabang::JENIS_CABANG,
        ];
    }

    public function test_kode_disimpan_huruf_besar_tanpa_spasi(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.lokasi.simpan'), $this->dataForm(['kode' => ' jt i ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cabang', ['kode' => 'JTI', 'nama' => 'Cabang Jati']);
    }

    public function test_hanya_boleh_satu_gudang_pusat_aktif(): void
    {
        $this->buatLokasi(Cabang::JENIS_PUSAT, ['nama' => 'Pusat Lama']);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.lokasi.simpan'), $this->dataForm(['jenis' => Cabang::JENIS_PUSAT]))
            ->assertSessionHasErrors('jenis');

        $this->assertSame(1, Cabang::pusat()->count());
    }

    public function test_gudang_pusat_nonaktif_tidak_bisa_diaktifkan_bila_ada_pusat_lain(): void
    {
        $this->buatLokasi(Cabang::JENIS_PUSAT);
        $pusatLama = $this->buatLokasi(Cabang::JENIS_PUSAT, ['aktif' => false]);

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.lokasi.aktif', $pusatLama), ['aktif' => 1])
            ->assertSessionHas('galat');

        $this->assertFalse($pusatLama->fresh()->aktif);
    }

    public function test_lokasi_dengan_pengguna_aktif_tidak_bisa_dinonaktifkan(): void
    {
        $cabang = $this->buatLokasi(Cabang::JENIS_CABANG);
        User::factory()->adminCabang($cabang->id)->create();

        $this->actingAs($this->adminUtama)
            ->patch(route('admin-utama.lokasi.aktif', $cabang), ['aktif' => 0])
            ->assertRedirect(route('admin-utama.lokasi.ubah', $cabang))
            ->assertSessionHas('galat');

        $this->assertTrue($cabang->fresh()->aktif);
    }

    public function test_jenis_tidak_bisa_diubah_bila_sudah_dipakai(): void
    {
        $cabang = $this->buatLokasi(Cabang::JENIS_CABANG, ['kode' => 'JTI', 'nama' => 'Cabang Jati']);
        User::factory()->adminCabang($cabang->id)->create();

        $this->actingAs($this->adminUtama)
            ->put(route('admin-utama.lokasi.perbarui', $cabang), $this->dataForm(['jenis' => Cabang::JENIS_PUSAT]))
            ->assertSessionHasErrors('jenis');

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.lokasi.ubah', $cabang))
            ->assertSee('Tidak bisa diubah karena lokasi ini sudah dipakai.');
    }

    public function test_lokasi_baru_belum_dipakai_bisa_dihapus_dan_yang_dipakai_tidak(): void
    {
        $kosong = $this->buatLokasi(Cabang::JENIS_CABANG);
        $dipakai = $this->buatLokasi(Cabang::JENIS_CABANG);
        User::factory()->adminCabang($dipakai->id)->create();

        $this->actingAs($this->adminUtama)->delete(route('admin-utama.lokasi.hapus', $kosong));
        $this->assertDatabaseMissing('cabang', ['id' => $kosong->id]);

        $this->actingAs($this->adminUtama)->delete(route('admin-utama.lokasi.hapus', $dipakai))->assertSessionHas('galat');
        $this->assertDatabaseHas('cabang', ['id' => $dipakai->id]);
    }

    public function test_lokasi_baru_otomatis_muncul_di_daftar_dan_peringatan_pusat(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.lokasi'))
            ->assertSee('Belum ada Gudang Pusat aktif');

        $this->buatLokasi(Cabang::JENIS_PUSAT, ['nama' => 'Gudang Utama']);
        $this->buatLokasi(Cabang::JENIS_CABANG, ['nama' => 'Cabang Baru Sekali']);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.lokasi'))
            ->assertDontSee('Belum ada Gudang Pusat aktif')
            ->assertSeeInOrder(['Gudang Utama', 'Cabang Baru Sekali']);
    }

    public function test_simpan_dan_tambah_lagi_membuka_popup_tambah_lagi(): void
    {
        $admin = User::factory()->adminUtama()->create();
        $tambah = route('admin-utama.lokasi.tambah', [], false);

        $this->actingAs($admin)
            ->post(route('admin-utama.lokasi.simpan'), ['nama' => 'Cabang Jati', 'kode' => 'JTI', 'jenis' => Cabang::JENIS_CABANG, 'tambah_lagi' => '1', '_popup' => $tambah])
            ->assertRedirect(route('admin-utama.lokasi'))
            ->assertSessionHas('popup_buka', $tambah);

        $this->get(route('admin-utama.lokasi'))->assertOk()
            ->assertSee('<meta name="popup-buka" content="'.$tambah.'">', false)
            ->assertSee('Lokasi Cabang Jati berhasil ditambahkan.');

        $this->post(route('admin-utama.lokasi.simpan'), ['nama' => 'Cabang Seroja', 'kode' => 'SRJ', 'jenis' => Cabang::JENIS_CABANG, 'tambah_lagi' => '1'])
            ->assertRedirect(route('admin-utama.lokasi.tambah'));
    }
}
