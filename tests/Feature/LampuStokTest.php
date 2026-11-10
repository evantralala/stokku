<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\EksporDaftarService;
use App\Services\PantauanStokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LampuStokTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private User $admin;
    private Cabang $pusat;
    private Cabang $cabang;
    private array $bahan = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(21, 0));
        Pengaturan::create(['kunci' => 'ambang_stok_menipis', 'nilai' => '20']);

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Kering']);

        // [kode, nama, saldo cabang (gram), minimum (gram)]
        foreach ([
            ['BB-0001', 'Mie ramen', 5000, 2000],
            ['BB-0002', 'Nori', 1100, 1000],
            ['BB-0003', 'Shoyu', 500, 1000],
            ['BB-0004', 'Kombu', 0, 1000],
            ['BB-0005', 'Wijen', -300, 0],
        ] as [$kode, $nama, $saldo, $min]) {
            $b = BahanBaku::create([
                'nama' => $nama, 'kategori_id' => $kategori->id,
                'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
                'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
            ]);
            $this->bahan[$nama] = $b;

            DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'saldo' => $saldo, 'diperbarui_pada' => now()]);
            BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'minimum' => $min]);
        }
    }

    public function test_lima_status_dipetakan_ke_tiga_lampu(): void
    {
        $daftar = app(PantauanStokService::class)->daftar($this->cabang);
        $lampu = $daftar->mapWithKeys(fn ($r) => [$r['bahan']->nama => $r['lampu']]);

        $this->assertSame(PantauanStokService::LAMPU_MERAH, $lampu['Wijen']);
        $this->assertSame(PantauanStokService::LAMPU_MERAH, $lampu['Kombu']);
        $this->assertSame(PantauanStokService::LAMPU_KUNING, $lampu['Shoyu']);
        $this->assertSame(PantauanStokService::LAMPU_KUNING, $lampu['Nori']);
        $this->assertSame(PantauanStokService::LAMPU_HIJAU, $lampu['Mie ramen']);

        $this->assertSame(['merah' => 2, 'kuning' => 2, 'hijau' => 1], PantauanStokService::hitungPerLampu($daftar));
    }

    public function test_halaman_stok_disaring_menurut_lampu(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['lampu' => PantauanStokService::LAMPU_KUNING]))
            ->assertOk()
            ->assertSee('Menipis')
            ->assertSee('Shoyu')
            ->assertSee('Nori')
            ->assertSee('class="strip-lampu lampu-kuning"', false)
            ->assertSee('Di Bawah Minimum')
            ->assertDontSee('Mie ramen')
            ->assertDontSee('Kombu');
    }

    public function test_tautan_saringan_status_dari_notifikasi_tetap_berlaku(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['status' => PantauanStokService::STATUS_MINUS]))
            ->assertOk()
            ->assertSee('Wijen')
            ->assertSee('aria-label="Hapus saringan Minus"', false)
            ->assertDontSee('Kombu');
    }

    public function test_bahan_tanpa_minimum_diberi_keterangan(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['cari' => 'wijen']))
            ->assertOk()
            ->assertSee('Min. belum diatur')
            ->assertSee('Belum Diatur');
    }

    public function test_riwayat_bahan_menampilkan_lampu_dan_minimum(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok.riwayat', $this->bahan['Shoyu']))
            ->assertOk()
            ->assertSee('class="kondisi-bahan strip-lampu lampu-kuning"', false)
            ->assertSee('Di Bawah Minimum')
            ->assertSee('Min. 1 kg');
    }

    public function test_dashboard_memakai_ringkasan_lampu(): void
    {
        $this->actingAs($this->adminUtama)
            ->get(route('admin-utama.dashboard'))
            ->assertOk()
            ->assertSee('Stok Habis / Menipis')
            ->assertSeeInOrder(['Ringkasan per Lokasi', 'Cabang Jati', '2 habis', '2 menipis']);

        $adminPusat = User::factory()->adminPusat($this->pusat->id)->create();
        DB::table('stok_saldo')->insert(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->bahan['Nori']->id, 'saldo' => 1100, 'diperbarui_pada' => now()]);
        BahanBakuMinimum::create(['cabang_id' => $this->pusat->id, 'bahan_baku_id' => $this->bahan['Nori']->id, 'minimum' => 1000]);

        $this->actingAs($adminPusat)
            ->get(route('pusat.dashboard'))
            ->assertOk()
            ->assertSee('Hampir Minimum')
            ->assertSee('class="strip-lampu lampu-kuning"', false);

        $this->actingAs($this->admin)
            ->get(route('cabang.dashboard'))
            ->assertOk()
            ->assertSee(route('cabang.stok', ['lampu' => PantauanStokService::LAMPU_MERAH]), false);
    }

    public function test_label_indikator_habis_menipis_dan_rinciannya(): void
    {
        $this->assertSame('Habis', PantauanStokService::label(PantauanStokService::STATUS_MINUS));
        $this->assertSame('Habis', PantauanStokService::label(PantauanStokService::STATUS_HABIS));
        $this->assertSame('Menipis', PantauanStokService::label(PantauanStokService::STATUS_KURANG));
        $this->assertSame('Menipis', PantauanStokService::label(PantauanStokService::STATUS_WASPADA));
        $this->assertSame('Aman', PantauanStokService::label(PantauanStokService::STATUS_AMAN));

        $this->assertSame('Minus', PantauanStokService::rincian(PantauanStokService::STATUS_MINUS));
        $this->assertNull(PantauanStokService::rincian(PantauanStokService::STATUS_HABIS));
        $this->assertNull(PantauanStokService::rincian(PantauanStokService::STATUS_AMAN));

        $this->assertSame('Menipis (di bawah minimum)', PantauanStokService::teks(PantauanStokService::STATUS_KURANG));
        $this->assertSame('Habis', PantauanStokService::teks(PantauanStokService::STATUS_HABIS));
    }

    public function test_setiap_baris_stok_menampilkan_indikator_habis_atau_menipis(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSee('class="status-stok lampu-merah"', false)
            ->assertSee('class="status-stok lampu-kuning"', false)
            ->assertSeeInOrder([
                'Wijen', 'Habis', 'Minus',
                'Kombu', 'Habis',
                'Shoyu', 'Menipis', 'Di Bawah Minimum',
                'Nori', 'Menipis', 'Hampir Minimum',
                'Mie ramen', 'Aman',
            ]);
    }

    public function test_status_di_excel_stok_memakai_label_indikator(): void
    {
        $nilai = collect(app(EksporDaftarService::class)->stok($this->cabang)->getActiveSheet()->toArray())->flatten();

        $this->assertTrue($nilai->contains('Habis (minus)'));
        $this->assertTrue($nilai->contains('Habis'));
        $this->assertTrue($nilai->contains('Menipis (di bawah minimum)'));
        $this->assertTrue($nilai->contains('Menipis (hampir minimum)'));
        $this->assertTrue($nilai->contains('Aman'));
    }
}
