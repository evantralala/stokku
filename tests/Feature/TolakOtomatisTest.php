<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Persetujuan;
use App\Models\Satuan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TolakOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang', 'jenis' => Cabang::JENIS_CABANG]);
        $gram = Satuan::create(['nama' => 'gram']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
    }

    private function permintaan(string $nomor, $batas, string $statusDetail = PermintaanDetail::STATUS_MENUNGGU): Permintaan
    {
        $p = Permintaan::create([
            'nomor' => $nomor, 'cabang_id' => $this->cabang->id, 'diajukan_pada' => now()->subDay(),
            'batas_keputusan' => $batas, 'sumber' => 'manual', 'status' => Permintaan::STATUS_MENUNGGU,
        ]);
        $p->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty_diminta' => 100, 'status' => $statusDetail]);

        return $p;
    }

    public function test_hanya_permintaan_lewat_batas_yang_ditolak_dan_tercatat_sebagai_keputusan_sistem(): void
    {
        $lewat = $this->permintaan('PM-1', now()->subMinute());
        $belum = $this->permintaan('PM-2', now()->addHour());
        $versi = \App\Support\VersiData::sekarang();

        $this->artisan('persetujuan:tolak-otomatis')
            ->expectsOutputToContain('1 barang permintaan ditolak otomatis')
            ->assertSuccessful();

        $this->assertNotSame($versi, \App\Support\VersiData::sekarang(), 'halaman yang terbuka harus ikut diperbarui');

        $this->assertSame(PermintaanDetail::STATUS_OTOMATIS_DITOLAK, $lewat->detail()->first()->status);
        $this->assertSame(Permintaan::STATUS_SELESAI, $lewat->fresh()->status);
        $this->assertSame(PermintaanDetail::STATUS_MENUNGGU, $belum->detail()->first()->status);

        $catatan = Persetujuan::first();
        $this->assertSame(Persetujuan::KEPUTUSAN_OTOMATIS_DITOLAK, $catatan->keputusan);
        $this->assertNull($catatan->oleh_user_id);
    }

    public function test_permintaan_dengan_barang_disetujui_tetap_terbuka_untuk_dikirim(): void
    {
        $p = $this->permintaan('PM-1', now()->subMinute());
        $p->detail()->create(['bahan_baku_id' => $this->nori->id, 'qty_diminta' => 50, 'qty_disetujui' => 50, 'status' => PermintaanDetail::STATUS_DISETUJUI]);

        $this->artisan('persetujuan:tolak-otomatis')->assertSuccessful();

        $this->assertSame(Permintaan::STATUS_MENUNGGU, $p->fresh()->status);
    }
}
