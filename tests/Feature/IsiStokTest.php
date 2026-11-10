<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Notifikasi;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\Satuan;
use App\Models\TambahStok;
use App\Models\User;
use App\Services\KontrolSistemService;
use App\Support\DaftarKontrol;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Centang bahan habis/menipis di halaman Stok, lalu isi lewat popup dengan jumlah awal minimum + 20%. */
class IsiStokTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $pusat;
    private Cabang $cabang;
    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private BahanBaku $beras;
    private BahanBaku $kecap;
    private BahanBaku $nori;
    private BahanBaku $tahu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $pokok = Kategori::create(['nama' => 'Bahan pokok', 'urutan' => 1]);
        $bahan = fn (string $nama, array $lain = []) => BahanBaku::create($lain + [
            'nama' => $nama, 'kategori_id' => $pokok->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        $this->beras = $bahan('Beras Jepang');
        $this->kecap = $bahan('Kecap asin');
        $this->nori = $bahan('Nori');
        $this->tahu = $bahan('Tahu');

        foreach ([$this->cabang, $this->pusat] as $lokasi) {
            DB::table('stok_saldo')->insert([
                ['cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->beras->id, 'saldo' => 8000, 'diperbarui_pada' => now()],
                ['cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 5000, 'diperbarui_pada' => now()],
            ]);
            BahanBakuMinimum::create(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->beras->id, 'minimum' => 10000]);
            BahanBakuMinimum::create(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->kecap->id, 'minimum' => 2500]);
            BahanBakuMinimum::create(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->tahu->id, 'minimum' => 1000]);
        }

        $this->izinkanUbahStokPusat();
    }

    private function saldo(Cabang $lokasi, BahanBaku $bahan): float
    {
        return (float) DB::table('stok_saldo')->where('cabang_id', $lokasi->id)->where('bahan_baku_id', $bahan->id)->value('saldo');
    }

    public function test_halaman_stok_punya_centang_hanya_untuk_bahan_habis_atau_menipis(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('value="'.$this->beras->id.'" data-pilih-isi', false)
            ->assertSee('value="'.$this->kecap->id.'" data-pilih-isi', false)
            ->assertDontSee('value="'.$this->nori->id.'" data-pilih-isi', false)
            ->assertSee('data-pilih-isi-semua', false)
            ->assertSee('data-dasar="'.route('cabang.stok.isi').'"', false)
            ->assertSee('Minta Semua (3)');

        $this->actingAs($this->adminPusat)->get(route('pusat.stok'))->assertOk()->assertSee('Isi Semua (3)');
        $this->actingAs($this->adminUtama)->get(route('admin-utama.stok', ['lokasi' => $this->cabang->id]))
            ->assertOk()
            ->assertSee('data-dasar="'.route('admin-utama.stok.isi', ['lokasi' => $this->cabang->id]).'"', false);
        $this->assertFalse(Route::has('cabang.permintaan.minta'));
    }

    public function test_popup_terisi_minimum_tambah_20_persen_dan_cabang_mengirim_satu_permintaan(): void
    {
        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.isi', ['bahan' => [$this->beras->id, $this->kecap->id, $this->nori->id]]))
            ->assertOk()
            ->assertSee('data-popup-isi', false)
            ->assertSee('name="baris['.$this->beras->id.'][qty]"', false)
            ->assertSee('value="12"', false)
            ->assertSee('value="3"', false)
            ->assertSee('Usulan: minimum + 20%')
            ->assertDontSee('name="baris['.$this->nori->id.'][qty]"', false)
            ->assertDontSee('name="baris['.$this->tahu->id.'][qty]"', false)
            ->assertSee('Kirim Permintaan');

        $this->post(route('cabang.stok.isi.simpan'), ['baris' => [
            $this->beras->id => ['pilih' => '1', 'bahan_baku_id' => $this->beras->id, 'qty' => '40'],
            $this->kecap->id => ['pilih' => '1', 'bahan_baku_id' => $this->kecap->id, 'qty' => '3'],
            $this->tahu->id  => ['bahan_baku_id' => $this->tahu->id, 'qty' => '1,2'],
        ]])
            ->assertRedirect(route('cabang.stok'))
            ->assertSessionHas('sukses', fn (string $pesan) => str_contains($pesan, 'terkirim ke Admin Utama: 2 bahan'));

        $permintaan = Permintaan::with('detail')->sole();
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $permintaan->status);
        $this->assertEquals(
            [$this->beras->id => 40000.0, $this->kecap->id => 3000.0],
            $permintaan->detail->mapWithKeys(fn ($d) => [$d->bahan_baku_id => (float) $d->qty_diminta])->all()
        );
        $this->assertSame(0.0, $this->saldo($this->cabang, $this->kecap));
        $this->assertSame(1, Notifikasi::where('user_id', $this->adminUtama->id)->count());

        $this->get(route('cabang.stok'))
            ->assertSee('Sudah Diminta')
            ->assertDontSee('value="'.$this->beras->id.'" data-pilih-isi', false)
            ->assertSee('Minta Semua (1)');

        $this->get(route('cabang.stok.isi', ['bahan' => [$this->beras->id]]))->assertSee('Sudah dalam permintaan yang berjalan: Beras Jepang.');

        $this->post(route('cabang.stok.isi.simpan'), ['baris' => [
            $this->beras->id => ['pilih' => '1', 'bahan_baku_id' => $this->beras->id, 'qty' => '5'],
        ]])->assertSessionHas('galat', 'Masih dalam permintaan yang belum selesai: Beras Jepang. Hapus centangnya lalu kirim lagi.');
        $this->assertSame(1, Permintaan::count());
    }

    public function test_tanpa_pilihan_popup_memuat_semua_dan_persen_mengikuti_pengaturan(): void
    {
        $this->seed(PengaturanSeeder::class);
        Pengaturan::simpan('persen_cadangan_minta', '50');

        $this->actingAs($this->adminCabang)
            ->get(route('cabang.stok.isi'))
            ->assertOk()
            ->assertSee('name="baris['.$this->beras->id.'][qty]"', false)
            ->assertSee('name="baris['.$this->kecap->id.'][qty]"', false)
            ->assertSee('name="baris['.$this->tahu->id.'][qty]"', false)
            ->assertSee('value="15"', false)
            ->assertSee('Usulan: minimum + 50%');
    }

    public function test_admin_pusat_menambah_stok_langsung_dengan_satu_pemberitahuan(): void
    {
        $this->actingAs($this->adminPusat)
            ->get(route('pusat.stok.isi', ['bahan' => [$this->kecap->id, $this->tahu->id]]))
            ->assertOk()
            ->assertSee('name="baris['.$this->tahu->id.'][qty]"', false)
            ->assertSee('Tambah Stok');

        $this->post(route('pusat.stok.isi.simpan'), ['baris' => [
            $this->kecap->id => ['pilih' => '1', 'bahan_baku_id' => $this->kecap->id, 'qty' => '3'],
            $this->tahu->id  => ['pilih' => '1', 'bahan_baku_id' => $this->tahu->id, 'qty' => '1,2'],
        ]])
            ->assertRedirect(route('pusat.stok'))
            ->assertSessionHas('sukses', 'Stok 2 bahan di Gudang Pusat bertambah. Tercatat di riwayat.');

        $this->assertSame(3000.0, $this->saldo($this->pusat, $this->kecap));
        $this->assertSame(1200.0, $this->saldo($this->pusat, $this->tahu));
        $this->assertSame(0.0, $this->saldo($this->cabang, $this->kecap));
        $this->assertSame(['Isi stok dari halaman Stok'], TambahStok::pluck('keterangan')->unique()->values()->all());
        $this->assertSame(0, Permintaan::count());

        $notifikasi = Notifikasi::where('user_id', $this->adminUtama->id)->sole();
        $this->assertSame('Stok ditambah langsung: Gudang Pusat', $notifikasi->judul);
        $this->assertStringStartsWith('2 bahan · ', $notifikasi->keterangan);
    }

    public function test_admin_utama_mengisi_lokasi_pilihan_dan_centang_kosong_ditolak(): void
    {
        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.stok.isi.simpan', ['lokasi' => $this->cabang->id]), ['baris' => [
                $this->beras->id => ['bahan_baku_id' => $this->beras->id, 'qty' => '12'],
            ]])
            ->assertSessionHasErrors(['baris' => 'Centang minimal satu bahan yang mau diisi.']);

        $this->post(route('admin-utama.stok.isi.simpan', ['lokasi' => $this->cabang->id]), [
            'baris'      => [$this->beras->id => ['pilih' => '1', 'bahan_baku_id' => $this->beras->id, 'qty' => '12']],
            'keterangan' => 'Belanja pasar',
        ])
            ->assertRedirect(route('admin-utama.stok', ['lokasi' => $this->cabang->id]))
            ->assertSessionHas('sukses');

        $this->assertSame(20000.0, $this->saldo($this->cabang, $this->beras));
        $this->assertSame('Belanja pasar', TambahStok::sole()->keterangan);
        $this->assertSame(0, Notifikasi::count());

        $this->post(route('admin-utama.stok.isi.simpan', ['lokasi' => $this->cabang->id]), ['baris' => [
            $this->kecap->id => ['pilih' => '1', 'bahan_baku_id' => $this->kecap->id, 'qty' => '0'],
        ]])->assertSessionHasErrors('baris.'.$this->kecap->id.'.qty');
    }

    public function test_cabang_tanpa_hak_permintaan_tidak_bisa_mengisi_stok(): void
    {
        app(KontrolSistemService::class)->aturAkses(User::ROLE_ADMIN_CABANG, [
            'permintaan' => [DaftarKontrol::AKSI_LIHAT => 1],
        ], $this->adminUtama);

        $this->actingAs($this->adminCabang)->get(route('cabang.stok'))
            ->assertOk()
            ->assertDontSee('Isi Semua')
            ->assertDontSee('Minta Semua')
            ->assertDontSee('data-pilih-isi', false);

        $this->get(route('cabang.stok.isi'))->assertRedirect(route('cabang.dashboard'));
        $this->post(route('cabang.stok.isi.simpan'), ['baris' => [
            $this->kecap->id => ['pilih' => '1', 'bahan_baku_id' => $this->kecap->id, 'qty' => '3'],
        ]])->assertSessionHas('galat');

        $this->assertSame(0.0, $this->saldo($this->cabang, $this->kecap));
        $this->assertSame(0, Permintaan::count());
        $this->assertSame(0, TambahStok::count());
    }

    public function test_hak_akses_tambah_stok_admin_pusat_bisa_dimatikan(): void
    {
        $this->actingAs($this->adminPusat)->get(route('pusat.stok'))
            ->assertDontSee(route('pusat.stok.tambah'), false)
            ->assertDontSee('Perbarui Lewat Excel')
            ->assertSee('Isi Semua');

        $this->aturHakAkses(User::ROLE_ADMIN_PUSAT, 'ubah-stok', DaftarKontrol::AKSI_CATAT, false);

        $this->get(route('pusat.stok'))
            ->assertOk()
            ->assertDontSee(route('pusat.stok.tambah'), false)
            ->assertDontSee('Isi Semua')
            ->assertDontSee('aria-label="Ubah stok', false);
        $this->get(route('pusat.stok.intip', $this->kecap))->assertOk()->assertDontSee('Tambah Stok')->assertDontSee('Ubah Stok');
        $this->get(route('pusat.stok.riwayat', $this->kecap))->assertOk()->assertDontSee('Tambah Stok')->assertDontSee('Ubah Stok');

        foreach (['pusat.stok.tambah', 'pusat.stok.isi'] as $rute) {
            $this->get(route($rute))->assertRedirect(route('pusat.dashboard'));
        }

        $this->post(route('pusat.stok.tambah.simpan'), ['bahan_baku_id' => $this->kecap->id, 'jumlah' => '2'])->assertSessionHas('galat');
        $this->post(route('pusat.stok.isi.simpan'), ['baris' => [
            $this->kecap->id => ['pilih' => '1', 'bahan_baku_id' => $this->kecap->id, 'qty' => '3'],
        ]])->assertSessionHas('galat');

        $this->assertSame(0.0, $this->saldo($this->pusat, $this->kecap));
        $this->assertSame(0, TambahStok::count());

        $this->actingAs($this->adminUtama)->get(route('admin-utama.kontrol-sistem.akses', 'pusat'))
            ->assertOk()
            ->assertSee('Tambah Stok', false);
    }
}
