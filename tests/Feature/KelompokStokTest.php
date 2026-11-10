<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\PantauanStokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KelompokStokTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Cabang $cabang;
    private array $kategori = [];

    protected function setUp(): void
    {
        parent::setUp();

        Pengaturan::create(['kunci' => 'ambang_stok_menipis', 'nilai' => '20']);

        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $this->admin = User::factory()->adminCabang($this->cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);

        // Urutan sengaja berbeda dari abjad.
        foreach ([['Sayur', 3], ['Protein', 1], ['Bumbu & saus', 2]] as [$nama, $urutan]) {
            $this->kategori[$nama] = Kategori::create(['nama' => $nama, 'urutan' => $urutan]);
        }

        // [kode, nama, kategori, saldo (gram), minimum (gram)]
        foreach ([
            ['BB-0001', 'Ayam fillet', 'Protein', 5000, 1000],
            ['BB-0002', 'Chashu', 'Protein', 0, 1000],
            ['BB-0003', 'Shoyu', 'Bumbu & saus', 1100, 1000],
            ['BB-0004', 'Miso', 'Bumbu & saus', 4000, 1000],
            ['BB-0005', 'Daun bawang', 'Sayur', 3000, 1000],
            ['BB-0006', 'Kol', 'Sayur', 2000, 500],
        ] as [$kode, $nama, $kategori, $saldo, $min]) {
            $b = BahanBaku::create([
                'nama' => $nama, 'kategori_id' => $this->kategori[$kategori]->id,
                'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
                'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
            ]);

            DB::table('stok_saldo')->insert(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'saldo' => $saldo, 'diperbarui_pada' => now()]);
            BahanBakuMinimum::create(['cabang_id' => $this->cabang->id, 'bahan_baku_id' => $b->id, 'minimum' => $min]);
        }
    }

    public function test_dikelompokkan_per_kategori_urut_sesuai_urutan(): void
    {
        $kelompok = PantauanStokService::kelompokkanPerKategori(app(PantauanStokService::class)->daftar($this->cabang));

        $this->assertSame(['Protein', 'Bumbu & saus', 'Sayur'], $kelompok->map(fn ($k) => $k['kategori']->nama)->all());
        $this->assertSame(['merah' => 1, 'kuning' => 0, 'hijau' => 1], $kelompok[0]['lampu']);
        $this->assertSame(['merah' => 0, 'kuning' => 1, 'hijau' => 1], $kelompok[1]['lampu']);
        $this->assertSame(['merah' => 0, 'kuning' => 0, 'hijau' => 2], $kelompok[2]['lampu']);
    }

    public function test_halaman_stok_menampilkan_kelompok_dan_ringkasan_lampu(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSeeInOrder([
                'id="kategori-'.$this->kategori['Protein']->id.'"', 'Chashu', 'Ayam fillet',
                'id="kategori-'.$this->kategori['Bumbu & saus']->id.'"', 'Shoyu', 'Miso',
                'id="kategori-'.$this->kategori['Sayur']->id.'"', 'Daun bawang', 'Kol',
            ], false)
            ->assertSee('1 habis')
            ->assertSee('2 aman');
    }

    public function test_kategori_bermasalah_terbuka_dan_yang_aman_tertutup(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertSee('id="kategori-'.$this->kategori['Protein']->id.'" open>', false)
            ->assertSee('id="kategori-'.$this->kategori['Bumbu & saus']->id.'" open>', false)
            ->assertSee('id="kategori-'.$this->kategori['Sayur']->id.'">', false);
    }

    public function test_pencarian_membuka_kategori_yang_cocok_saja(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['cari' => 'kol']))
            ->assertOk()
            ->assertSee('id="kategori-'.$this->kategori['Sayur']->id.'" open>', false)
            ->assertDontSee('id="kategori-'.$this->kategori['Protein']->id.'"', false)
            ->assertSee('Kol')
            ->assertDontSee('Daun bawang');
    }

    public function test_rel_kategori_urut_sesuai_urutan_dan_mengikuti_saringan(): void
    {
        $rel = fn (Kategori $k) => 'data-saring-kategori="'.$k->id.'"';

        $this->actingAs($this->admin)
            ->get(route('cabang.stok'))
            ->assertOk()
            ->assertSeeInOrder([
                '<nav class="rel-kategori" aria-label="Kategori">',
                'data-saring-kategori=""', 'Semua Kategori',
                $rel($this->kategori['Protein']), $rel($this->kategori['Bumbu & saus']), $rel($this->kategori['Sayur']),
            ], false);

        $this->actingAs($this->admin)
            ->get(route('cabang.stok', ['lampu' => PantauanStokService::LAMPU_MERAH]))
            ->assertOk()
            ->assertSee($rel($this->kategori['Protein']), false)
            ->assertDontSee($rel($this->kategori['Bumbu & saus']), false)
            ->assertSee('Chashu')
            ->assertDontSee('Shoyu');
    }
}
