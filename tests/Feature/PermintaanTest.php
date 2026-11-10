<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\PermintaanDetail;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermintaanTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;
    private User $admin;
    private BahanBaku $nori;
    private BahanBaku $mie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(9, 0));

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Kering']);
        $buat = fn (string $kode, string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $this->nori = $buat('BB-0001', 'Nori');
        $this->mie = $buat('BB-0002', 'Mie ramen');

        DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'saldo' => 400, 'diperbarui_pada' => now()]);
        BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $this->nori->id, 'minimum' => 1000]);
    }

    private function data(array $ubah = []): array
    {
        return $ubah + [
            'baris'     => [
                ['bahan_baku_id' => $this->nori->id, 'qty' => '1,5', 'alasan' => 'Ramai'],
                ['bahan_baku_id' => '', 'qty' => '', 'alasan' => ''],
            ],
        ];
    }

    public function test_ajukan_permintaan_dengan_salinan_kondisi_stok_dan_batas_keputusan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cabang.permintaan.simpan'), $this->data())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sukses');

        $permintaan = Permintaan::first();
        $this->assertSame('PM-JTI-'.now()->format('ymd').'-001', $permintaan->nomor);
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $permintaan->status);
        $this->assertSame(Permintaan::SUMBER_MANUAL, $permintaan->sumber);
        $this->assertSame($this->admin->id, $permintaan->diajukan_oleh);
        $this->assertTrue($permintaan->batas_keputusan->equalTo(now()->addHours(24)));

        $detail = $permintaan->detail()->first();
        $this->assertSame('1500.000', $detail->qty_diminta);
        $this->assertSame('400.000', $detail->sisa_saat_itu);
        $this->assertSame('1000.000', $detail->minimum_saat_itu);
        $this->assertSame(PermintaanDetail::STATUS_MENUNGGU, $detail->status);
    }

    public function test_validasi_bahan_ganda_jumlah_dan_tanggal(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cabang.permintaan.simpan'), $this->data([
                'baris' => [
                    ['bahan_baku_id' => $this->nori->id, 'qty' => '0'],
                ],
            ]))
            ->assertSessionHasErrors(['baris.0.qty']);

        $this->assertSame(0, Permintaan::count());
    }

    public function test_bahan_dobel_dijumlahkan_dan_jumlah_boleh_perkalian(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cabang.permintaan.simpan'), $this->data([
                'baris' => [
                    ['bahan_baku_id' => $this->nori->id, 'qty' => '1'],
                    ['bahan_baku_id' => $this->nori->id, 'qty' => '2x2'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $detail = Permintaan::sole()->detail()->sole();
        $this->assertSame(5.0, $this->nori->keSatuanBeli((float) $detail->qty_diminta));
    }

    public function test_batalkan_hanya_bila_belum_ada_keputusan(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.permintaan.simpan'), $this->data());
        $this->actingAs($this->admin)->post(route('cabang.permintaan.simpan'), $this->data());
        [$pertama, $kedua] = Permintaan::orderBy('id')->get();

        $this->actingAs($this->admin)
            ->patch(route('cabang.permintaan.batal', $pertama))
            ->assertSessionHas('sukses');
        $this->assertSame(Permintaan::STATUS_BATAL, $pertama->fresh()->status);

        $kedua->detail()->update(['status' => PermintaanDetail::STATUS_DISETUJUI, 'qty_disetujui' => 1000]);

        $this->actingAs($this->admin)
            ->patch(route('cabang.permintaan.batal', $kedua))
            ->assertSessionHas('galat');
        $this->assertSame(Permintaan::STATUS_MENUNGGU, $kedua->fresh()->status);
    }

    public function test_form_usulan_berisi_bahan_di_bawah_minimum(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.permintaan'))
            ->assertSee('1 bahan di bawah stok minimum');

        $this->actingAs($this->admin)
            ->get(route('cabang.permintaan.buat', ['usulan' => 1]))
            ->assertOk()
            ->assertSee('value="Nori"', false)
            ->assertSee('Stok sekarang 0,4 kg · minimum 1 kg')
            ->assertDontSee('value="Mie ramen"', false);
    }

    public function test_akses_terbatas_pada_cabang_sendiri(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.permintaan.simpan'), $this->data());
        $permintaan = Permintaan::first();

        $lain = Cabang::create(['kode' => 'PWS', 'nama' => 'Cabang Lain', 'jenis' => Cabang::JENIS_CABANG]);
        $adminLain = User::factory()->adminCabang($lain->id)->create();
        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang', 'jenis' => Cabang::JENIS_PUSAT]);

        $this->actingAs($adminLain)->get(route('cabang.permintaan.lihat', $permintaan))->assertNotFound();
        $this->actingAs(User::factory()->adminPusat($pusat->id)->create())->get(route('cabang.permintaan'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('cabang.permintaan.lihat', $permintaan))->assertOk()->assertSee('1,5 kg');
    }

    public function test_daftar_dan_detail_permintaan_ringkas(): void
    {
        $this->actingAs($this->admin)->post(route('cabang.permintaan.simpan'), $this->data());
        $permintaan = Permintaan::first();

        $this->get(route('cabang.permintaan'))
            ->assertOk()
            ->assertSee(route('cabang.permintaan.lihat', $permintaan), false)
            ->assertSee('Manual')
            ->assertDontSee('Mendesak')
            ->assertSee('1 barang');

        $this->get(route('cabang.permintaan.lihat', $permintaan))
            ->assertOk()
            ->assertSeeInOrder(['Nori', 'Ramai', '0,4 kg', '1,5 kg', 'Menunggu'])
            ->assertDontSee('Disetujui 1,5 kg');
    }
}
