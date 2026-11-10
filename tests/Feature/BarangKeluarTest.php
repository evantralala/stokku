<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\BarangKeluarDetail;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BarangKeluarTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $admin;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 2000, 'diperbarui_pada' => now()]);
    }

    private function data(array $ubah = [], array $baris = []): array
    {
        return $ubah + [
            'tanggal' => now()->toDateString(),
            'baris'   => [$baris + [
                'bahan_baku_id' => $this->nori->id,
                'qty'           => '0,5',
                'alasan'        => 'Lembap terkena air',
            ]],
        ];
    }

    private function saldo(): float
    {
        return app(StokService::class)->saldo($this->cabang->id, $this->nori->id);
    }

    public function test_catat_dengan_alasan_menunggu_persetujuan_tanpa_mengubah_stok(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cabang.barang-keluar.simpan'), $this->data())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $dokumen = BarangKeluar::first();
        $this->assertSame('BK-JTI-'.now()->format('ymd').'-001', $dokumen->nomor);
        $this->assertSame(BarangKeluar::JENIS_RUSAK, $dokumen->jenis);
        $this->assertSame(BarangKeluar::STATUS_MENUNGGU, $dokumen->status);

        $detail = $dokumen->detail()->first();
        $this->assertSame('500.000', $detail->qty);
        $this->assertSame('Lembap terkena air', $detail->penjelasan);
        $this->assertSame(BarangKeluarDetail::SEBAB_RUSAK, $detail->sebab);

        $this->assertSame(StokMutasi::JENIS_RUSAK, StokMutasi::first()->jenis);
        $this->assertSame('-500.000', StokMutasi::first()->jumlah);
        $this->assertSame(2000.0, $this->saldo());
    }

    public function test_alasan_wajib_dan_jumlah_tidak_boleh_melebihi_stok(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cabang.barang-keluar.simpan'), $this->data([], ['alasan' => '  ']))
            ->assertSessionHasErrors(['baris.0.alasan' => 'Tulis alasannya, mis. kemasan bocor.']);

        $this->actingAs($this->admin)
            ->post(route('cabang.barang-keluar.simpan'), $this->data([], ['alasan' => str_repeat('a', 161)]))
            ->assertSessionHasErrors('baris.0.alasan');

        $this->actingAs($this->admin)
            ->post(route('cabang.barang-keluar.simpan'), $this->data([], ['qty' => '3']))
            ->assertSessionHas('galat');

        $this->assertSame(0, BarangKeluar::count());
    }

    public function test_form_hanya_meminta_bahan_jumlah_dan_alasan(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.barang-keluar.catat'))
            ->assertOk()
            ->assertSee('name="baris[0][alasan]"', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('Saksi')
            ->assertDontSee('Sebab')
            ->assertDontSee('Apa yang Terjadi')
            ->assertDontSee('Foto Bukti');
    }

    public function test_admin_utama_menyetujui_dan_stok_berkurang(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.barang-keluar.simpan'), $this->data());
        $dokumen = BarangKeluar::first();

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan', ['jenis' => BarangKeluar::DOKUMEN_TIPE]))
            ->assertSee($dokumen->nomor);

        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan.barang-keluar', $dokumen))
            ->assertOk()
            ->assertSee('Lembap terkena air')
            ->assertDontSee('Saksi')
            ->assertDontSee('Foto bukti', false);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-keluar.putuskan', $dokumen), ['aksi' => 'setujui'])
            ->assertSessionHas('sukses');

        $this->assertSame(BarangKeluar::STATUS_DISETUJUI, $dokumen->fresh()->status);
        $this->assertSame(1500.0, $this->saldo());
    }

    public function test_persetujuan_gagal_bila_stok_sudah_lebih_kecil(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.barang-keluar.simpan'), $this->data());
        $dokumen = BarangKeluar::first();

        DB::table('stok_saldo')->update(['saldo' => 100]);

        $this->actingAs($this->adminUtama)
            ->post(route('admin-utama.persetujuan.barang-keluar.putuskan', $dokumen), ['aksi' => 'setujui'])
            ->assertSessionHas('galat');

        $this->assertSame(BarangKeluar::STATUS_MENUNGGU, $dokumen->fresh()->status);
        $this->assertSame(100.0, $this->saldo());
    }

    public function test_dikembalikan_lalu_diperbaiki_dengan_alasan_baru(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.barang-keluar.simpan'), $this->data());
        $dokumen = BarangKeluar::first();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.barang-keluar.putuskan', $dokumen), [
            'aksi' => 'revisi', 'catatan' => 'Jumlahnya terlalu banyak',
        ]);
        $this->assertSame(BarangKeluar::STATUS_REVISI, $dokumen->fresh()->status);

        $this->actingAs($this->admin)
            ->get(route('cabang.barang-keluar.ubah', $dokumen))
            ->assertOk()
            ->assertSee('value="Lembap terkena air"', false);

        $this->actingAs($this->admin)
            ->put(route('cabang.barang-keluar.perbarui', $dokumen), $this->data([], ['qty' => '0,2', 'alasan' => 'Sebagian masih bagus']))
            ->assertSessionHasNoErrors();

        $this->assertSame(BarangKeluar::STATUS_MENUNGGU, $dokumen->fresh()->status);
        $baru = $dokumen->detail()->first();
        $this->assertSame('200.000', $baru->qty);
        $this->assertSame('Sebagian masih bagus', $baru->penjelasan);
        $this->assertSame(['batal', 'menunggu'], StokMutasi::orderBy('id')->pluck('status')->all());
    }

    public function test_tolak_dan_batalkan_tidak_mengubah_stok(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.barang-keluar.simpan'), $this->data());
        $this->actingAs($this->admin)->post(route('cabang.barang-keluar.simpan'), $this->data());
        [$a, $b] = BarangKeluar::orderBy('id')->get();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.barang-keluar.putuskan', $a), [
            'aksi' => 'tolak', 'catatan' => 'Tidak ada bukti',
        ]);
        $this->actingAs($this->admin)->patch(route('cabang.barang-keluar.batal', $b));

        $this->assertSame(BarangKeluar::STATUS_BATAL, $a->fresh()->status);
        $this->assertSame(BarangKeluar::STATUS_BATAL, $b->fresh()->status);
        $this->assertSame(2000.0, $this->saldo());
    }

    public function test_admin_pusat_juga_bisa_mencatat_di_gudangnya(): void
    {
        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $adminPusat = User::factory()->adminPusat($pusat->id)->create();
        DB::table('stok_saldo')->insert(['cabang_id' => $pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 5000, 'diperbarui_pada' => now()]);

        $this->actingAs($adminPusat)
            ->post(route('pusat.barang-keluar.simpan'), $this->data())
            ->assertSessionHasNoErrors();

        $this->assertSame($pusat->id, BarangKeluar::first()->cabang_id);

        $this->actingAs($this->admin)
            ->get(route('cabang.barang-keluar.lihat', BarangKeluar::first()))
            ->assertNotFound();
    }

    public function test_menu_dan_halaman_memakai_nama_barang_rusak(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.barang-keluar'))
            ->assertOk()
            ->assertSee('Barang Rusak')
            ->assertDontSee('Barang keluar')
            ->assertSee('Belum ada barang rusak.');

        $this->post(route('cabang.barang-keluar.simpan'), $this->data())->assertSessionHas('sukses');
        $dokumen = BarangKeluar::first();

        $this->get(route('cabang.barang-keluar'))
            ->assertOk()
            ->assertSee(route('cabang.barang-keluar.lihat', $dokumen), false)
            ->assertSee('1 barang');

        $this->get(route('cabang.barang-keluar.lihat', $dokumen))
            ->assertOk()
            ->assertSeeInOrder(['Nori', 'Lembap terkena air', '0,5 kg'])
            ->assertDontSee('Rusak / Busuk');
    }

    public function test_data_lama_dengan_sebab_dan_foto_tetap_tampil_tanpa_galat(): void
    {
        $dokumen = BarangKeluar::create([
            'nomor' => 'BK-LAMA-1', 'cabang_id' => $this->cabang->id, 'tanggal' => now()->toDateString(),
            'jenis' => BarangKeluar::JENIS_RUSAK, 'dicatat_oleh' => $this->admin->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
        ]);
        $dokumen->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty' => 100, 'sebab' => 'rusak', 'penjelasan' => null]);

        $this->actingAs($this->admin)->get(route('cabang.barang-keluar.lihat', $dokumen))->assertOk()->assertSee('Nori');
        $this->get(route('cabang.barang-keluar.pdf', $dokumen))->assertOk();
        $this->get(route('cabang.barang-keluar.unduh', ['periode' => 'bulan-ini']))->assertOk();
        $this->actingAs($this->adminUtama)->get(route('admin-utama.persetujuan.barang-keluar', $dokumen))->assertOk();
        $this->get(route('admin-utama.laporan.rusak'))->assertOk()->assertSee('Nori')->assertDontSee('Per Sebab');
    }

    public function test_migrasi_memindahkan_sebab_lama_ke_alasan_dan_membuang_foto_serta_saksi(): void
    {
        $migrasi = require database_path('migrations/2026_11_06_000001_ganti_sebab_rusak_jadi_alasan.php');
        $migrasi->down();

        $dokumen = BarangKeluar::create([
            'nomor' => 'BK-LAMA-2', 'cabang_id' => $this->cabang->id, 'tanggal' => now()->toDateString(),
            'jenis' => BarangKeluar::JENIS_RUSAK, 'dicatat_oleh' => $this->admin->id, 'status' => BarangKeluar::STATUS_MENUNGGU,
        ]);
        $baris = fn (string $sebab, ?string $penjelasan) => DB::table('barang_keluar_detail')->insertGetId([
            'barang_keluar_id' => $dokumen->id, 'bahan_baku_id' => $this->nori->id, 'qty' => 100, 'sebab' => $sebab,
            'penjelasan' => $penjelasan, 'foto_path' => 'barang-keluar/2026/10/a.jpg', 'diketahui_oleh' => $this->admin->id,
        ]);
        $tumpah = $baris('tumpah', 'Jatuh dari rak');
        $kedaluwarsa = $baris('kedaluwarsa', null);
        $rusak = $baris('rusak', 'Basah');

        $migrasi->up();

        $this->assertSame('Tumpah / Jatuh: Jatuh dari rak', BarangKeluarDetail::find($tumpah)->penjelasan);
        $this->assertSame('Kedaluwarsa', BarangKeluarDetail::find($kedaluwarsa)->penjelasan);
        $this->assertSame('Basah', BarangKeluarDetail::find($rusak)->penjelasan);
        $this->assertSame(['rusak'], BarangKeluarDetail::pluck('sebab')->unique()->values()->all());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('barang_keluar_detail', 'foto_path'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('barang_keluar_detail', 'diketahui_oleh'));
    }
}
