<?php

namespace Tests\Feature;

use App\Exceptions\StokTidakCukup;
use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Models\User;
use App\Services\StokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class StokServiceTest extends TestCase
{
    use RefreshDatabase;

    private StokService $stok;
    private Cabang $lokasi;
    private BahanBaku $bahan;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stok = app(StokService::class);
        $this->user = User::factory()->adminUtama()->create();
        $this->lokasi = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang', 'jenis' => Cabang::JENIS_PUSAT]);

        $gram = Satuan::create(['nama' => 'gram']);
        $this->bahan = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
    }

    private function catat(float $jumlah, int $dokumenId = 1): void
    {
        $this->stok->catat(
            [['cabang_id' => $this->lokasi->id, 'bahan_baku_id' => $this->bahan->id, 'jumlah' => $jumlah, 'dokumen_detail_id' => $dokumenId]],
            StokMutasi::JENIS_MASUK_SUPPLIER,
            'uji',
            $dokumenId,
            $this->user->id
        );
    }

    private function saldo(): float
    {
        return $this->stok->saldo($this->lokasi->id, $this->bahan->id);
    }

    public function test_catat_belum_mengubah_saldo_dan_sahkan_menambah_sekali_saja(): void
    {
        $this->catat(500);
        $this->assertSame(0.0, $this->saldo());

        $this->assertSame(1, $this->stok->sahkan('uji', 1));
        $this->assertSame(500.0, $this->saldo());

        $this->assertSame(0, $this->stok->sahkan('uji', 1));
        $this->assertSame(500.0, $this->saldo());
    }

    public function test_batalkan_tidak_mengubah_saldo_dan_tidak_bisa_disahkan_lagi(): void
    {
        $this->catat(500);

        $this->assertSame(1, $this->stok->batalkan('uji', 1));
        $this->assertSame(0, $this->stok->sahkan('uji', 1));
        $this->assertSame(0.0, $this->saldo());
        $this->assertSame(StokMutasi::STATUS_BATAL, StokMutasi::first()->status);
    }

    public function test_pengeluaran_melebihi_saldo_ditolak_dan_tidak_ada_yang_berubah(): void
    {
        $this->catat(100, 1);
        $this->stok->sahkan('uji', 1);

        $this->catat(-300, 2);

        try {
            $this->stok->sahkan('uji', 2);
            $this->fail('Seharusnya stok tidak cukup.');
        } catch (StokTidakCukup $e) {
            $this->assertSame('Nori', $e->namaBahan);
        }

        $this->assertSame(100.0, $this->saldo());
        $this->assertSame(StokMutasi::STATUS_MENUNGGU, StokMutasi::where('dokumen_id', 2)->value('status'));
    }

    public function test_mutasi_tidak_bisa_diubah_jumlahnya_atau_dihapus(): void
    {
        $this->catat(100);
        $mutasi = StokMutasi::first();

        $this->expectException(LogicException::class);
        $mutasi->update(['jumlah' => 999]);
    }
}
