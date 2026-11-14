<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Satuan;
use App\Models\User;
use App\Services\JumlahMenu;
use App\Support\SatuanTunggal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Angka penanda menu Persetujuan dihitung per dokumen, sama dengan antrean di halaman Persetujuan. */
class JumlahMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_persetujuan_dihitung_per_dokumen_bukan_per_barang(): void
    {
        $cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $kategori = Kategori::create(['nama' => 'Kering', 'urutan' => 1]);
        $satuan = Satuan::create(['nama' => 'kg']);
        $bahan = collect(['Beras', 'Garam', 'Gula'])->map(fn (string $nama) => BahanBaku::create(
            ['nama' => $nama, 'kategori_id' => $kategori->id, 'toleransi_persen' => 5] + SatuanTunggal::untuk($satuan),
        ));

        $permintaan = fn (string $nomor, string $statusBarang) => tap(
            Permintaan::create(['nomor' => $nomor, 'cabang_id' => $cabang->id, 'status' => Permintaan::STATUS_MENUNGGU]),
            fn (Permintaan $p) => $bahan->each(fn (BahanBaku $b) => PermintaanDetail::create([
                'permintaan_id' => $p->id, 'bahan_baku_id' => $b->id, 'qty_diminta' => 1, 'status' => $statusBarang,
            ])),
        );
        $permintaan('PM-1', PermintaanDetail::STATUS_MENUNGGU);
        $permintaan('PM-2', PermintaanDetail::STATUS_MENUNGGU);
        $permintaan('PM-3', PermintaanDetail::STATUS_DISETUJUI);

        $jumlah = app(JumlahMenu::class);

        $this->assertSame(2, $jumlah->untuk(User::factory()->adminUtama()->create())['persetujuan_menunggu']);
        $this->assertSame(0, $jumlah->untuk(User::factory()->adminCabang($cabang->id)->create())['persetujuan_menunggu']);
    }
}
