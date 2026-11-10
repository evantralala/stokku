<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BarangKeluar;
use App\Models\BarangMasuk;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Opname;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\FotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** PDF bukti barang masuk, berita acara barang rusak, hasil stock opname, dan ZIP semua foto. */
class CetakTransaksiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Storage::fake('public');

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => Satuan::create(['nama' => 'gram'])->id, 'satuan_beli_id' => Satuan::create(['nama' => 'kg'])->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        foreach ([$this->pusat, $this->cabang] as $lokasi) {
            DB::table('stok_saldo')->insert(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 10000, 'diperbarui_pada' => now()]);
        }
    }

    private function pdf(string $url): string
    {
        $isi = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $isi);

        return $isi;
    }

    public function test_bukti_barang_masuk_untuk_pusat_dan_admin_utama(): void
    {
        $this->actingAs($this->adminPusat)->post(route('pusat.barang-masuk.simpan'), [
            'tanggal' => now()->toDateString(), 'catatan' => 'Nota 123',
            'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '2,5', 'keterangan' => 'Dus penyok']],
        ])->assertSessionHasNoErrors();
        $dokumen = BarangMasuk::firstOrFail();

        $this->actingAs($this->adminPusat)->get(route('pusat.barang-masuk.lihat', $dokumen))
            ->assertSee(route('pusat.barang-masuk.pdf', $dokumen), false);

        $isi = $this->pdf(route('pusat.barang-masuk.pdf', $dokumen));
        $this->assertStringContainsString('(Bukti Barang Masuk) Tj', $isi);
        $this->assertStringContainsString('(2,5) Tj', $isi);
        $this->assertStringContainsString('(Dus penyok) Tj', $isi);
        $this->assertStringContainsString('Nota 123', $isi);

        $this->actingAs($this->adminUtama)->get(route('admin-utama.persetujuan.barang-masuk', $dokumen))
            ->assertSee(route('admin-utama.persetujuan.barang-masuk.pdf', $dokumen), false);
        $this->pdf(route('admin-utama.persetujuan.barang-masuk.pdf', $dokumen));

        $this->actingAs($this->adminCabang)->get(route('pusat.barang-masuk.pdf', $dokumen))->assertForbidden();
    }

    public function test_berita_acara_barang_rusak_hanya_untuk_lokasinya(): void
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.barang-keluar.simpan'), [
            'tanggal' => now()->toDateString(),
            'baris'   => [['bahan_baku_id' => $this->nori->id, 'qty' => '0,5', 'alasan' => 'Lembab']],
        ])->assertSessionHasNoErrors();
        $dokumen = BarangKeluar::firstOrFail();

        $isi = $this->pdf(route('cabang.barang-keluar.pdf', $dokumen));
        $this->assertStringContainsString('(Berita Acara Barang Rusak) Tj', $isi);
        $this->assertStringContainsString('(Lembab) Tj', $isi);
        $this->assertStringContainsString('(Alasan) Tj', $isi);
        $this->assertStringNotContainsString('(Saksi) Tj', $isi);
        $this->assertStringContainsString('Menunggu Persetujuan', $isi);

        $this->actingAs($this->adminPusat)->get(route('pusat.barang-keluar.pdf', $dokumen))->assertNotFound();
        $this->actingAs($this->adminUtama)->get(route('admin-utama.persetujuan.barang-keluar', $dokumen))
            ->assertSee(route('admin-utama.persetujuan.barang-keluar.pdf', $dokumen), false);
    }

    public function test_hasil_opname_menyembunyikan_stok_sistem_sampai_disetujui(): void
    {
        $this->actingAs($this->adminCabang)->post(route('cabang.opname.mulai'));
        $opname = Opname::firstOrFail();
        $this->actingAs($this->adminCabang)->put(route('cabang.opname.simpan', $opname), [
            'aksi'   => 'kirim',
            'hitung' => [$opname->detail()->first()->id => ['qty' => '9,5', 'catatan' => 'Ada yang sobek']],
        ])->assertSessionHasNoErrors();

        $isi = $this->pdf(route('cabang.opname.pdf', $opname));
        $this->assertStringContainsString('(Hasil Stock Opname) Tj', $isi);
        $this->assertStringContainsString('(9,5) Tj', $isi);
        $this->assertStringNotContainsString('(Sistem) Tj', $isi);
        $this->assertStringNotContainsString('(-0,5) Tj', $isi);

        $this->actingAs($this->adminUtama);
        $isi = $this->pdf(route('admin-utama.persetujuan.opname.pdf', $opname));
        $this->assertStringContainsString('(Sistem) Tj', $isi);
        $this->assertStringContainsString('(10) Tj', $isi);
        $this->assertStringContainsString('(-0,5) Tj', $isi);

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.opname.putuskan', $opname), ['aksi' => 'setujui'])
            ->assertSessionHas('sukses');

        $this->actingAs($this->adminCabang);
        $isi = $this->pdf(route('cabang.opname.pdf', $opname));
        $this->assertStringContainsString('(Sistem) Tj', $isi);
        $this->assertStringContainsString('Disetujui', $isi);
    }

}
