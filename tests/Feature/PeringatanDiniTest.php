<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\Opname;
use App\Models\PenjualanHarian;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\PeringatanDiniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeringatanDiniTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;
    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(10, 0));

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);

        $gram = Satuan::create(['nama' => 'gram']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
    }

    private function peringatan(): Collection
    {
        return app(PeringatanDiniService::class)->daftar();
    }

    private function cari(string $teks): ?array
    {
        return $this->peringatan()->first(fn ($p) => str_contains($p['judul'], $teks));
    }

    private function penjualan(string $tanggal, ?float $pakai = null): void
    {
        $bk = BarangKeluar::create([
            'nomor' => 'BK-'.(++$this->urut), 'cabang_id' => $this->cabang->id, 'tanggal' => $tanggal,
            'jenis' => BarangKeluar::JENIS_PEMAKAIAN, 'dicatat_oleh' => $this->adminUtama->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
        ]);
        PenjualanHarian::create(['cabang_id' => $this->cabang->id, 'tanggal' => $tanggal, 'barang_keluar_id' => $bk->id, 'dicatat_oleh' => $this->adminUtama->id]);

        if ($pakai !== null) {
            DB::table('stok_mutasi')->insert([
                'cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'jumlah' => -$pakai,
                'jenis' => StokMutasi::JENIS_PAKAI_DAPUR, 'status' => StokMutasi::STATUS_SAH,
                'dokumen_tipe' => BarangKeluar::DOKUMEN_TIPE, 'dokumen_id' => $bk->id, 'dibuat_pada' => now(),
            ]);
        }
    }

    public function test_stok_minus_tingkat_tinggi_dan_diurutkan_paling_atas(): void
    {
        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => -50, 'diperbarui_pada' => now()]);
        $this->penjualan(now()->subDay()->toDateString());

        $p = $this->peringatan()->first();
        $this->assertSame(PeringatanDiniService::TINGKAT_TINGGI, $p['tingkat']);
        $this->assertSame('1 bahan stoknya minus', $p['judul']);
        $this->assertSame('Cabang Jati', $p['lokasi']);
        $this->assertStringContainsString('Nori', $p['keterangan']);
    }

    public function test_selisih_kiriman_berulang(): void
    {
        $kirim = fn () => Kiriman::create([
            'nomor' => 'KR-'.(++$this->urut), 'dari_cabang_id' => $this->pusat->id, 'ke_cabang_id' => $this->cabang->id,
            'status' => Kiriman::STATUS_DICHECKLIST, 'dikirim_pada' => now()->subDays(2), 'sampai_pada' => now()->subDay(),
        ]);

        $kirim();
        $this->assertSame(PeringatanDiniService::TINGKAT_SEDANG, $this->cari('kiriman diterima dengan selisih')['tingkat']);

        $kirim();
        $this->assertSame(PeringatanDiniService::TINGKAT_TINGGI, $this->cari('2 kiriman diterima dengan selisih')['tingkat']);
    }

    public function test_barang_rusak_berulang(): void
    {
        foreach (range(1, 3) as $i) {
            BarangKeluar::create([
                'nomor' => 'BK-R'.$i, 'cabang_id' => $this->cabang->id, 'tanggal' => now()->subDays($i)->toDateString(),
                'jenis' => BarangKeluar::JENIS_RUSAK, 'dicatat_oleh' => $this->adminUtama->id, 'status' => BarangKeluar::STATUS_DISETUJUI,
            ]);
        }

        $p = $this->cari('laporan barang rusak');
        $this->assertSame('3 laporan barang rusak/terbuang', $p['judul']);
        $this->assertSame(PeringatanDiniService::TINGKAT_SEDANG, $p['tingkat']);
    }

    public function test_opname_dengan_selisih_di_luar_toleransi(): void
    {
        $opname = Opname::create([
            'nomor' => 'OP-1', 'cabang_id' => $this->cabang->id, 'tanggal' => now()->toDateString(),
            'petugas_id' => $this->adminUtama->id, 'status' => Opname::STATUS_DISETUJUI,
        ]);
        $opname->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty_sistem' => 1000, 'qty_fisik' => 800]);

        $p = $this->cari('Opname OP-1');
        $this->assertSame('Opname OP-1: 1 bahan selisih di luar toleransi', $p['judul']);
        $this->assertStringContainsString('Nori', $p['keterangan']);
    }

    public function test_opname_yang_mengunci_stok_terlalu_lama(): void
    {
        $opname = Opname::create([
            'nomor' => 'OP-2', 'cabang_id' => $this->pusat->id, 'tanggal' => now()->subDays(2)->toDateString(),
            'petugas_id' => $this->adminUtama->id, 'status' => Opname::STATUS_BERJALAN,
        ]);
        $opname->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->assertNotNull($this->cari('Stok dikunci opname'));
    }

    public function test_pemakaian_melonjak_dibanding_hari_sebelumnya(): void
    {
        foreach ([4, 3, 2] as $mundur) {
            $this->penjualan(now()->subDays($mundur)->toDateString(), 100);
        }
        $this->penjualan(now()->subDay()->toDateString(), 300);

        $p = $this->cari('melonjak');
        $this->assertSame('Pemakaian 1 bahan melonjak', $p['judul']);
        $this->assertStringContainsString('Nori', $p['keterangan']);
    }

    public function test_penjualan_belum_dicatat(): void
    {
        $this->assertNull($this->cari('belum dicatat'), 'Cabang yang baru dibuat hari ini belum diperiksa.');

        $this->cabang->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->assertSame(PeringatanDiniService::TINGKAT_TINGGI, $this->cari('3 hari terakhir belum dicatat')['tingkat']);

        $this->penjualan(now()->subDays(2)->toDateString());
        $this->assertSame(PeringatanDiniService::TINGKAT_SEDANG, $this->cari('kemarin belum dicatat')['tingkat']);

        $this->penjualan(now()->subDay()->toDateString());
        $this->assertNull($this->cari('belum dicatat'));
    }
}
