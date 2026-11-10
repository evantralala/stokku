<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\LogPerubahan;
use App\Models\PenyesuaianStok;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Admin Utama mengosongkan stok bahan terpilih di satu lokasi; riwayat tetap utuh. */
class KosongkanStokTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $cabang;
    private User $adminUtama;
    private BahanBaku $beras;
    private BahanBaku $nori;
    private BahanBaku $kecap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $k = Kategori::create(['nama' => 'Bahan Pokok', 'urutan' => 1]);
        $buat = fn ($nama) => BahanBaku::create(['nama' => $nama, 'kategori_id' => $k->id, 'satuan_dasar_id' => $gram->id,
            'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5]);
        $this->beras = $buat('Beras Jepang');
        $this->nori = $buat('Nori');
        $this->kecap = $buat('Kecap Lama');

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 500, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->kecap->id, 'saldo' => 1500, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 2000, 'diperbarui_pada' => now()],
        ]);
        $this->kecap->update(['aktif' => false]);
    }

    private function saldo(Cabang $l, BahanBaku $b): float
    {
        return app(StokService::class)->saldo($l->id, $b->id);
    }

    private function kosongkan(array $isi)
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.stok.kosongkan.simpan', ['lokasi' => $this->pusat->id]), $isi);
    }

    public function test_popup_menampilkan_bahan_yang_masih_ada_stoknya_termasuk_nonaktif(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSee(route('admin-utama.stok.kosongkan', ['lokasi' => $this->pusat->id]), false);

        $this->get(route('admin-utama.stok.kosongkan', ['lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertDontSee('checked', false)
            ->assertSeeInOrder(['Pilih semua bahan (3)', 'Beras Jepang', 'Stok 8 kg → 0', 'Kecap Lama', '1,5 kg → 0 · nonaktif', 'Nori'])
            ->assertSee('Ketik KOSONGKAN');
    }

    public function test_kosongkan_bahan_terpilih_tercatat_di_kartu_stok_dan_log(): void
    {
        $this->kosongkan(['bahan' => [$this->beras->id, $this->kecap->id], 'alasan' => 'Kedaluwarsa dibuang', 'konfirmasi' => 'kosongkan'])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSessionHas('sukses', 'Stok 2 bahan di Gudang Pusat dikosongkan. Tercatat di riwayat stok sebagai penyesuaian.');

        $this->assertSame(0.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame(0.0, $this->saldo($this->pusat, $this->kecap));
        $this->assertSame(500.0, $this->saldo($this->pusat, $this->nori));
        $this->assertSame(2000.0, $this->saldo($this->cabang, $this->beras), 'lokasi lain tidak ikut');

        $this->assertSame(2, PenyesuaianStok::count());
        $this->assertSame('Dikosongkan: Kedaluwarsa dibuang', PenyesuaianStok::where('bahan_baku_id', $this->beras->id)->value('keterangan'));
        $this->assertTrue(StokMutasi::where('bahan_baku_id', $this->beras->id)->where('jumlah', -8000)->where('jenis', StokMutasi::JENIS_PENYESUAIAN)->exists());

        $log = LogPerubahan::where('aksi', 'stok.kosongkan')->sole();
        $this->assertSame('Kosongkan stok 2 bahan di Gudang Pusat: Kedaluwarsa dibuang.', $log->ringkasan);
        $this->assertCount(2, $log->rincian);
    }

    public function test_konfirmasi_alasan_dan_hak_akses(): void
    {
        $this->kosongkan(['bahan' => [$this->beras->id, $this->nori->id], 'alasan' => 'x', 'konfirmasi' => 'HAPUS'])->assertSessionHasErrors('konfirmasi');
        $this->kosongkan(['bahan' => [$this->beras->id, $this->nori->id], 'alasan' => 'x'])->assertSessionHasErrors('konfirmasi');
        $this->kosongkan(['bahan' => [$this->beras->id], 'konfirmasi' => 'KOSONGKAN'])->assertSessionHasErrors('alasan');
        $this->kosongkan(['alasan' => 'x', 'konfirmasi' => 'KOSONGKAN'])->assertSessionHasErrors('bahan');
        $this->assertSame(8000.0, $this->saldo($this->pusat, $this->beras));

        $adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->actingAs($adminPusat)->get(route('admin-utama.stok.kosongkan'))->assertForbidden();
        $this->actingAs($adminPusat)->get(route('pusat.stok'))->assertDontSee('Kosongkan');
    }

    public function test_ditolak_saat_stock_opname_dan_bila_sudah_nol(): void
    {
        $this->pusat->update(['opname_aktif' => true]);
        $this->kosongkan(['bahan' => [$this->beras->id], 'alasan' => 'x', 'konfirmasi' => 'KOSONGKAN'])
            ->assertSessionHas('galat', 'Gudang Pusat sedang stock opname. Selesaikan opname dulu.');

        $this->pusat->update(['opname_aktif' => false]);
        $this->kosongkan(['bahan' => [$this->beras->id], 'alasan' => 'x', 'konfirmasi' => 'KOSONGKAN'])->assertSessionHas('sukses');
        $this->kosongkan(['bahan' => [$this->beras->id], 'alasan' => 'x', 'konfirmasi' => 'KOSONGKAN'])
            ->assertSessionHas('galat', 'Stok bahan yang dipilih sudah 0. Tidak ada yang dikosongkan.');
    }

    public function test_ikon_sampah_baris_hanya_untuk_bahan_itu_tanpa_ketik_kata(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertSee(e(route('admin-utama.stok.kosongkan', ['bahan' => $this->beras->id, 'lokasi' => $this->pusat->id])), false)
            ->assertSee('aria-label="Kosongkan stok Beras Jepang"', false);

        $this->get(route('admin-utama.stok.kosongkan', ['bahan' => $this->beras->id, 'lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('Kosongkan Beras Jepang')
            ->assertSee('8 kg → 0')
            ->assertSee('name="bahan[]" value="'.$this->beras->id.'"', false)
            ->assertDontSee('Nori')
            ->assertDontSee('Pilih semua bahan')
            ->assertDontSee('Ketik KOSONGKAN');

        $this->kosongkan(['bahan' => [$this->beras->id], 'alasan' => 'Basi'])->assertSessionHas('sukses');
        $this->assertSame(0.0, $this->saldo($this->pusat, $this->beras));
        $this->assertSame(500.0, $this->saldo($this->pusat, $this->nori));
    }

    public function test_satu_centang_untuk_isi_dan_kosongkan(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok', ['lokasi' => $this->pusat->id]))
            ->assertDontSee('data-mode-kosongkan', false)
            ->assertSee('data-kosong-tombol hidden', false)
            ->assertSee('Centang bahan untuk diisi atau dikosongkan')
            ->assertSee('value="'.$this->beras->id.'" data-pilih-isi aria-label="Pilih Beras Jepang"', false)
            ->assertSee('data-isi="1" data-kosong="1"', false)
            ->assertSee('data-isi="0" data-kosong="1"', false)
            ->assertSee('data-pilih-isi-semua', false);

        $this->get(route('admin-utama.stok.isi', ['bahan' => [$this->nori->id], 'lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('Nori')
            ->assertDontSee('Beras Jepang');

        $this->get(route('admin-utama.stok.kosongkan', ['bahan' => [$this->beras->id, $this->nori->id], 'lokasi' => $this->pusat->id]))
            ->assertOk()
            ->assertSee('2 bahan dipilih')
            ->assertSeeInOrder(['Beras Jepang', 'Nori'])
            ->assertDontSee('Kecap Lama')
            ->assertDontSee('Pilih semua bahan')
            ->assertSee('Ketik KOSONGKAN')
            ->assertSee('value="'.$this->nori->id.'" checked', false);
    }
}
