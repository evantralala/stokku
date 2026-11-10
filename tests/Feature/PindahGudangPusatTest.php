<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\BarangMasukService;
use App\Services\StokService;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PindahGudangPusatTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminJati;
    private Cabang $pusat;
    private Cabang $jati;
    private BahanBaku $nori;
    private BahanBaku $beras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PengaturanSeeder::class);
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->jati = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create(['password' => 'rahasia-utama']);
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminJati = User::factory()->adminCabang($this->jati->id)->create();

        $kategori = Kategori::create(['nama' => 'Kering']);
        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $buat = fn (string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = $buat('Nori');
        $this->beras = $buat('Beras');
        $tahu = $buat('Tahu');

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 4000, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 2500, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $tahu->id, 'saldo' => -300, 'diperbarui_pada' => now()],
            ['cabang_id' => $this->jati->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 900, 'diperbarui_pada' => now()],
        ]);
        BahanBakuMinimum::create(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 1000]);
    }

    private function data(array $ubah = []): array
    {
        return $ubah + ['nama' => 'Gudang Kota Baru', 'kode' => ' gp2 ', 'alamat' => 'Jl. Raya Kota Baru', 'password' => 'rahasia-utama'];
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return app(StokService::class)->saldo($lokasi->id, $bahan->id);
    }

    public function test_tombol_dan_ringkasan_yang_ikut_pindah(): void
    {
        $this->actingAs($this->adminUtama)->get(route('admin-utama.lokasi'))
            ->assertOk()
            ->assertSee(route('admin-utama.lokasi.pindah-pusat'), false);

        $this->get(route('admin-utama.lokasi.ubah', $this->pusat))->assertOk()->assertSee('Pindah Gudang Pusat');
        $this->get(route('admin-utama.lokasi.ubah', $this->jati))->assertOk()->assertDontSee('Pindah Gudang Pusat');

        $this->get(route('admin-utama.lokasi.pindah-pusat'))
            ->assertOk()
            ->assertSee('2 bahan')
            ->assertSee('2 Admin Pusat')
            ->assertSee('1 stok minimum')
            ->assertSee('1 bahan yang stoknya minus');
    }

    public function test_stok_admin_dan_stok_minimum_pindah_lalu_gudang_lama_nonaktif(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.lokasi.pindah-pusat.simpan'), $this->data())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin-utama.lokasi'))
            ->assertSessionHas('sukses');

        $baru = Cabang::where('kode', 'GP2')->sole();
        $this->assertTrue($baru->isPusat());
        $this->assertTrue($baru->aktif);
        $this->assertFalse($this->pusat->fresh()->aktif);
        $this->assertSame($baru->id, Cabang::pusatAktifSelain(null)->id);

        $this->assertSame(4000.0, $this->saldo($baru, $this->nori));
        $this->assertSame(2500.0, $this->saldo($baru, $this->beras));
        $this->assertSame(0.0, $this->saldo($this->pusat, $this->nori));
        $this->assertSame(900.0, $this->saldo($this->jati, $this->nori));

        $kiriman = Kiriman::sole();
        $this->assertSame(Kiriman::STATUS_SELESAI, $kiriman->status);
        $this->assertSame([$this->pusat->id, $baru->id], [$kiriman->dari_cabang_id, $kiriman->ke_cabang_id]);
        $this->assertSame(0, StokMutasi::menunggu()->count());

        $this->assertSame(2, User::where('cabang_id', $baru->id)->count());
        $this->assertSame($baru->id, BahanBakuMinimum::sole()->cabang_id);
        $this->assertDatabaseHas('log_perubahan', ['aksi' => 'lokasi.pindah-pusat', 'user_id' => $this->adminUtama->id]);

        $this->actingAs($this->adminPusat->fresh())->get(route('pusat.stok'))->assertOk()->assertSee('Gudang Kota Baru');
        $this->get(route('pusat.stok.mutasi', ['tampil' => 'transaksi']))->assertOk()->assertSee($kiriman->nomor)
            ->assertSee(route('pusat.terima-barang.lihat', $kiriman), false);
        $this->get(route('pusat.terima-barang.lihat', $kiriman))->assertOk()->assertSee('Pindah Gudang Pusat');
        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok.mutasi', ['lokasi' => $this->pusat->id, 'tampil' => 'transaksi']))->assertOk();
    }

    public function test_dokumen_yang_belum_selesai_dan_password_salah_menahan_pemindahan(): void
    {
        app(BarangMasukService::class)->catat($this->adminPusat, $this->pusat, [
            'tanggal' => now()->toDateString(), 'baris' => [['bahan_baku_id' => $this->nori->id, 'qty' => 2]],
        ]);

        $this->actingAs($this->adminUtama)->get(route('admin-utama.lokasi.pindah-pusat'))
            ->assertOk()
            ->assertSee('Belum bisa dipindah')
            ->assertSee('masih menunggu persetujuan');

        $this->post(route('admin-utama.lokasi.pindah-pusat.simpan'), $this->data())->assertSessionHas('galat');
        $this->post(route('admin-utama.lokasi.pindah-pusat.simpan'), $this->data(['password' => 'salah', 'kode' => 'JTI']))
            ->assertSessionHasErrors(['password', 'kode']);

        $this->assertTrue($this->pusat->fresh()->aktif);
        $this->assertSame(2, Cabang::count());
        $this->assertSame(4000.0, $this->saldo($this->pusat, $this->nori));
    }

    public function test_hanya_admin_utama(): void
    {
        $this->actingAs($this->adminPusat)->get(route('admin-utama.lokasi.pindah-pusat'))->assertForbidden();
        $this->actingAs($this->adminJati)->post(route('admin-utama.lokasi.pindah-pusat.simpan'), $this->data())->assertForbidden();
        $this->assertTrue($this->pusat->fresh()->aktif);
    }
}
