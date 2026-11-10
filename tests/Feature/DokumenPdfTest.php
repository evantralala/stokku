<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Kiriman;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PDF permintaan (semua barang dan statusnya) dan surat jalan kiriman. */
class DokumenPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $adminPusat;
    private User $adminCabang;
    private Cabang $pusat;
    private Cabang $cabang;
    private BahanBaku $nori;
    private Permintaan $permintaan;
    private PermintaanDetail $detail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        $this->adminCabang = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);

        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 10000, 'diperbarui_pada' => now()]);

        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => [['bahan_baku_id' => $this->nori->id, 'qty' => '4']],
        ]);
        $this->permintaan = Permintaan::first();
        $this->detail = $this->permintaan->detail()->first();

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $this->permintaan), [
            'keputusan' => [$this->detail->id => ['aksi' => 'setujui', 'qty' => '4']],
        ]);
    }

    /** Kirim permintaan; $stokGudang (kg) mengatur stok gudang lebih dulu, karena jumlah kiriman mengikuti persetujuan dan stok yang ada. */
    private function kirim(?string $stokGudang = null)
    {
        if ($stokGudang !== null) {
            DB::table('stok_saldo')->where('cabang_id', $this->pusat->id)->where('bahan_baku_id', $this->nori->id)
                ->update(['saldo' => (float) str_replace(',', '.', $stokGudang) * 1000]);
        }

        return $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.simpan', $this->permintaan));
    }

    private function pdf(string $url): string
    {
        $respons = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $isi = $respons->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $isi);
        $this->assertStringEndsWith('%%EOF', $isi);

        return $isi;
    }

    public function test_pdf_permintaan_untuk_admin_utama_pusat_dan_cabang(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.persetujuan.permintaan', $this->permintaan))
            ->assertSee(route('admin-utama.kiriman.permintaan-pdf', $this->permintaan), false);

        $isi = $this->pdf(route('admin-utama.kiriman.permintaan-pdf', $this->permintaan));
        $this->assertStringContainsString('(Permintaan Barang) Tj', $isi);
        $this->assertStringContainsString('(Nori) Tj', $isi);
        $this->assertStringContainsString('(4) Tj', $isi);
        $this->assertStringContainsString('(Disetujui 4) Tj', $isi);
        $this->assertStringContainsString($this->permintaan->nomor, $isi);

        $this->actingAs($this->adminPusat)->get(route('pusat.kiriman.buat', $this->permintaan))
            ->assertSee(route('pusat.kiriman.permintaan-pdf', $this->permintaan), false);
        $this->pdf(route('pusat.kiriman.permintaan-pdf', $this->permintaan));

        $this->actingAs($this->adminCabang)->get(route('cabang.permintaan.lihat', $this->permintaan))
            ->assertSee(route('cabang.permintaan.pdf', $this->permintaan), false);
        $this->pdf(route('cabang.permintaan.pdf', $this->permintaan));
    }

    public function test_daftar_siap_kirim_pdf_dan_permintaan_yang_belum_diputuskan_tetap_bisa_dicetak(): void
    {
        $this->actingAs($this->adminPusat)->get(route('pusat.kiriman'))
            ->assertSee(route('pusat.kiriman.siap-kirim-pdf'), false);

        $isi = $this->pdf(route('pusat.kiriman.siap-kirim-pdf'));
        $this->assertStringContainsString('(Daftar Permintaan Siap Kirim) Tj', $isi);
        $this->assertStringContainsString('(1 permintaan belum terkirim penuh) Tj', $isi);

        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris' => [['bahan_baku_id' => $this->nori->id, 'qty' => '1']],
        ]);
        $baru = Permintaan::latest('id')->first();

        $this->get(route('cabang.permintaan.lihat', $baru))->assertSee(route('cabang.permintaan.pdf', $baru), false);
        $isi = $this->pdf(route('cabang.permintaan.pdf', $baru));
        $this->assertStringContainsString('(Menunggu) Tj', $isi);
        $this->assertStringContainsString('(Status: 1 menunggu.) Tj', $isi);
    }

    public function test_surat_jalan_berisi_tanggal_barang_dan_jumlah(): void
    {
        $this->kirim('3');
        $kiriman = Kiriman::sole();

        $this->actingAs($this->adminPusat)->get(route('pusat.kiriman.lihat', $kiriman))
            ->assertSee(route('pusat.kiriman.surat-jalan', $kiriman), false);

        $isi = $this->pdf(route('pusat.kiriman.surat-jalan', $kiriman));
        $this->assertStringContainsString('(Surat Jalan) Tj', $isi);
        $this->assertStringContainsString('(Tanggal kirim: '.$kiriman->dikirim_pada->translatedFormat('j M Y, H.i').') Tj', $isi);
        $this->assertStringContainsString('(Dari Gudang Pusat ke Cabang Jati) Tj', $isi);
        $this->assertStringNotContainsString('Kendaraan', $isi);
        $this->assertStringContainsString('(Nori) Tj', $isi);
        $this->assertStringContainsString('(3) Tj', $isi);

        $this->actingAs($this->adminUtama);
        $this->pdf(route('admin-utama.kiriman.surat-jalan', $kiriman));

        $this->actingAs($this->adminCabang)->get(route('cabang.terima-barang.lihat', $kiriman))
            ->assertOk()
            ->assertDontSee('surat-jalan', false);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('cabang.terima-barang.surat-jalan'));
    }

    public function test_pdf_permintaan_memuat_semua_status_dengan_susunan_tabel_surat_jalan(): void
    {
        $gram = Satuan::where('nama', 'gram')->first();
        $kering = Kategori::first();
        $bahan = fn (string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kering->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id,
            'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
        $wijen = $bahan('Wijen');
        $kombu = $bahan('Kombu');
        $miso = $bahan('Miso');
        DB::table('stok_saldo')->insert([
            ['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $wijen->id, 'saldo' => 1000, 'diperbarui_pada' => now()],
        ]);

        $this->actingAs($this->adminCabang)->post(route('cabang.permintaan.simpan'), [
            'baris'     => [
                ['bahan_baku_id' => $wijen->id, 'qty' => '300'],
                ['bahan_baku_id' => $kombu->id, 'qty' => '200'],
                ['bahan_baku_id' => $miso->id, 'qty' => '100'],
            ],
        ]);
        $permintaan = Permintaan::latest('id')->first();
        $detail = $permintaan->detail()->get()->keyBy('bahan_baku_id');

        $this->actingAs($this->adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
            'keputusan' => [
                $detail[$wijen->id]->id => ['aksi' => 'setujui', 'qty' => '250'],
                $detail[$kombu->id]->id => ['aksi' => 'tolak', 'alasan' => 'Stok pusat kosong'],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->adminPusat)->post(route('pusat.kiriman.simpan', $permintaan), [
            'jumlah' => [$detail[$wijen->id]->id => '250'],
        ])->assertSessionHasNoErrors();
        $kiriman = Kiriman::latest('id')->first();

        $isi = $this->pdf(route('pusat.kiriman.permintaan-pdf', $permintaan));
        $this->assertStringContainsString('(Dikirim 250) Tj', $isi);

        $this->actingAs($this->adminCabang)->post(route('cabang.terima-barang.simpan', $kiriman), ['kesesuaian' => 'sesuai'])
            ->assertSessionHasNoErrors();

        $isi = $this->pdf(route('cabang.permintaan.pdf', $permintaan));
        foreach (['No', 'Nama Barang', 'Jumlah', 'Satuan', 'Status', 'Kombu', 'Miso', 'Wijen', '(Ditolak)', '(Menunggu)', '(Diterima 250)', '(300)'] as $teks) {
            $this->assertStringContainsString(str_starts_with($teks, '(') ? $teks.' Tj' : '('.$teks.') Tj', $isi);
        }
        $this->assertStringContainsString('(Status: 1 menunggu, 1 disetujui, 1 ditolak.) Tj', $isi);
        $this->assertTrue(strpos($isi, '(Kombu) Tj') < strpos($isi, '(Miso) Tj') && strpos($isi, '(Miso) Tj') < strpos($isi, '(Wijen) Tj'));
    }
}
