<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\Satuan;
use App\Models\StokMutasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermintaanOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        Pengaturan::create(['kunci' => 'jam_permintaan_otomatis', 'nilai' => '06:00']);
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        Cabang::create(['kode' => 'GP', 'nama' => 'Gudang', 'jenis' => Cabang::JENIS_PUSAT]);

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 400, 'diperbarui_pada' => now()]);
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 1000]);
    }

    public function test_sebelum_jamnya_tidak_membuat_apa_pun(): void
    {
        $this->travelTo(now()->setTime(5, 30));

        $this->artisan('permintaan:buat-otomatis')->assertSuccessful();

        $this->assertSame(0, Permintaan::count());
    }

    public function test_membuat_permintaan_dibulatkan_ke_satuan_beli_dan_hanya_sekali_sehari(): void
    {
        $this->travelTo(now()->setTime(6, 5));

        $this->artisan('permintaan:buat-otomatis')->expectsOutputToContain('Cabang Jati: 1 bahan diminta.')->assertSuccessful();
        $this->artisan('permintaan:buat-otomatis')->assertSuccessful();

        $this->assertSame(1, Permintaan::count());

        $permintaan = Permintaan::first();
        $this->assertSame(Permintaan::SUMBER_OTOMATIS, $permintaan->sumber);
        $this->assertNull($permintaan->diajukan_oleh);

        // Target minimum 1000 + cadangan 20% = 1200, saldo 400 -> kurang 800 gram, dibulatkan ke atas menjadi 1 kg.
        $this->assertSame('1000.000', $permintaan->detail()->first()->qty_diminta);
    }

    public function test_bahan_yang_sedang_diminta_tidak_diminta_lagi(): void
    {
        $this->travelTo(now()->setTime(7, 0));

        $this->artisan('permintaan:buat-otomatis', ['--paksa' => true]);
        $this->artisan('permintaan:buat-otomatis', ['--paksa' => true])
            ->expectsOutputToContain('Tidak ada bahan cabang yang di bawah minimum.');

        $this->assertSame(1, Permintaan::count());
    }

    public function test_jumlah_mengikuti_minimum_dan_cadangan_bukan_pemakaian(): void
    {
        $this->travelTo(now()->setTime(7, 0));
        $this->seed(\Database\Seeders\PengaturanSeeder::class);
        Pengaturan::simpan('persen_cadangan_minta', '150');

        DB::table('stok_mutasi')->insert([
            'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => -70000,
            'jenis' => StokMutasi::JENIS_PAKAI_DAPUR, 'status' => StokMutasi::STATUS_SAH,
            'dokumen_tipe' => 'penjualan', 'dokumen_id' => 1, 'dibuat_pada' => now()->subDays(2),
        ]);

        $this->artisan('permintaan:buat-otomatis', ['--paksa' => true]);

        // Target 1000 + 150% = 2500 - saldo 400 = 2100 gram -> dibulatkan ke atas 3 kg; pemakaian dapur tidak dihitung.
        $this->assertSame('3000.000', Permintaan::first()->detail()->first()->qty_diminta);
    }
}
