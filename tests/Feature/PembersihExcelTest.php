<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use App\Services\Impor\PembersihImpor;
use App\Support\PembersihExcel;
use App\Support\PencocokNama;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Pembersih isian Excel (tanpa AI): kapital, spasi, angka, tanggal, satuan, dan tebakan nama yang salah ketik. */
class PembersihExcelTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $pusat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Bahan Pokok', 'urutan' => 1]);

        foreach (['Beras Jepang', 'Bawang Putih', 'Mie Ramen'] as $nama) {
            BahanBaku::create([
                'nama' => $nama, 'kategori_id' => $kategori->id, 'satuan_dasar_id' => $gram->id,
                'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
            ]);
        }
    }

    public function test_angka_berbagai_tulisan(): void
    {
        foreach ([
            '2.5' => 2.5, '2,5' => 2.5, '2½' => 2.5, '1.000' => 1000, '1.250,75' => 1250.75, '1,250.75' => 1250.75,
            '1 1/2' => 1.5, '½' => 0.5, 'Rp 5.000' => 5000, '0.500' => 0.5, ' 3 ' => 3, '-1,5' => -1.5,
        ] as $teks => $harap) {
            $this->assertEquals($harap, PembersihExcel::angka($teks), $teks);
        }

        foreach (['abc', '2 kg', '1.2.3', '', '1/0'] as $teks) {
            $this->assertNull(PembersihExcel::angka($teks), $teks);
        }

        $this->assertSame(7, PembersihExcel::angka(7));
    }

    public function test_tanggal_berbagai_format(): void
    {
        foreach (['16/10/2026', '2026-10-16', '16-10-26', '16.10.2026', '2026/10/16', '16 Okt 2026', '16 oktober 2026', '16/10/2026 08:30', 46311, '46311'] as $teks) {
            $this->assertSame('2026-10-16', PembersihExcel::tanggal($teks), (string) $teks);
        }

        $this->assertNull(PembersihExcel::tanggal('31/02/2026'));
        $this->assertNull(PembersihExcel::tanggal('besok'));
    }

    public function test_satuan_sinonim_dan_yang_ada_di_sistem(): void
    {
        $this->assertSame('kg', PembersihExcel::satuan('Kg'));
        $this->assertSame('kg', PembersihExcel::satuan('kilo'));
        $this->assertSame('kg', PembersihExcel::satuan('KG'));
        $this->assertSame('gram', PembersihExcel::satuan('gr'));
        $this->assertSame('Kg', PembersihExcel::satuan('kilogram', ['Kg', 'gram']));
        $this->assertSame('ikat', PembersihExcel::satuan('Ikat'));
    }

    public function test_kapital_dan_spasi(): void
    {
        $this->assertSame('Beras Jepang', PembersihExcel::kapital(PembersihExcel::teks('  beras   jepang ')));
        $this->assertSame('Beras Jepang', PembersihExcel::kapital('BERAS JEPANG'));
        $this->assertSame('Air Mineral 600 ml', PembersihExcel::kapital('air mineral 600 ML'));
        $this->assertSame('Daging & Seafood', PembersihExcel::kapital('DAGING & SEAFOOD'));
        $this->assertSame('iPhone Case', PembersihExcel::kapital('iPhone Case'));
    }

    public function test_pencocok_nama_pas_tebak_dan_tidak(): void
    {
        $cocok = new PencocokNama(['Beras Jepang', 'Bawang Putih', 'Bawang Bombay', 'Air Mineral 600 ml', 'Kol', 'GP' => 'Gudang Pusat']);

        $this->assertSame(['pas', 'Beras Jepang'], array_values(array_slice($cocok->cari('beras  JEPANG'), 0, 2)));
        $this->assertSame(['pas', 'Gudang Pusat'], array_values(array_slice($cocok->cari('gp'), 0, 2)));

        foreach (['Brs Jepang' => 'Beras Jepang', 'Beras Jepnag' => 'Beras Jepang', 'Bwg Putih' => 'Bawang Putih', 'Air Mineral' => 'Air Mineral 600 ml'] as $ketik => $harap) {
            $hasil = $cocok->cari($ketik);
            $this->assertSame([PencocokNama::TEBAK, $harap], [$hasil['jenis'], $hasil['nama']], $ketik);
        }

        $this->assertSame(PencocokNama::TIDAK, $cocok->cari('Bawang')['jenis'], 'dua calon sama mirip: jangan menebak');
        $this->assertSame(PencocokNama::TIDAK, $cocok->cari('Kool')['jenis'], 'nama pendek: jangan menebak');
        $this->assertSame(PencocokNama::TIDAK, $cocok->cari('Tepung Terigu')['jenis']);
    }

    private function csv(string $judul, array $baris): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('data.csv', $judul."\n".implode("\n", $baris)."\n");
    }

    private function pratinjau(string $master, UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.pratinjau', $master), ['file' => $file])->assertOk();
    }

    private function simpan(string $master, TestResponse $pratinjau, array $tebakan = []): TestResponse
    {
        return $this->actingAs($this->adminUtama)->post(route('admin-utama.excel.simpan', $master), [
            'kunci' => $pratinjau->viewData('kunci'), 'tebakan' => $tebakan,
        ]);
    }

    public function test_salah_ketik_ditanyakan_dan_tidak_disimpan_tanpa_jawaban(): void
    {
        $file = fn () => $this->csv('lokasi,bahan,minimum', [
            'gp,beras jepang,"2,5"',
            'GP,Brs Jepang,3',
            'Gudang Pusat,Bawang Putih,2½',
            'GP,Tepung,1',
        ]);

        $pratinjau = $this->pratinjau('stok-minimum', $file());
        $baris = $pratinjau->viewData('baris')->keyBy('baris');

        $this->assertSame('siap', $baris[2]['status']);
        $this->assertContains('beras jepang → Beras Jepang', $baris[2]['betul']);
        $this->assertSame('cek', $baris[3]['status']);
        $this->assertSame('siap', $baris[4]['status']);
        $this->assertContains('2½ dibaca 2,5', $baris[4]['betul']);
        $this->assertSame('salah', $baris[5]['status']);
        $this->assertSame(2, $pratinjau->viewData('dibetulkan'));

        $tanya = $pratinjau->viewData('tebakan');
        $this->assertCount(1, $tanya);
        $this->assertSame(['Bahan', 'Brs Jepang', 'Beras Jepang', [3]], [$tanya[0]['label'], $tanya[0]['asli'], $tanya[0]['saran'], $tanya[0]['baris']]);
        $pratinjau->assertSee('maksudnya <strong>Beras Jepang</strong>?', false)
            ->assertSee('name="tebakan['.$tanya[0]['kunci'].']"', false);

        $this->simpan('stok-minimum', $pratinjau)->assertSessionHas('sukses');
        $this->assertSame(2, BahanBakuMinimum::count(), 'baris 2 dan 4 disimpan; baris yang ditebak menunggu jawaban');
        $this->assertEquals(2500, BahanBakuMinimum::where('bahan_baku_id', BahanBaku::where('nama', 'Beras Jepang')->value('id'))->value('minimum'));
    }

    public function test_tebakan_diterima_memakai_nama_di_sistem(): void
    {
        $pratinjau = $this->pratinjau('stok-minimum', $this->csv('lokasi,bahan,minimum', ['GP,Bwg Putih,4', 'GP,Mie Ramn,"1.000"']));
        $kunci = collect($pratinjau->viewData('tebakan'))->pluck('kunci', 'asli');

        $this->simpan('stok-minimum', $pratinjau, [
            $kunci['Bwg Putih'] => PembersihImpor::TERIMA,
            $kunci['Mie Ramn']  => PembersihImpor::TOLAK,
        ])->assertSessionHas('sukses');

        $bawang = BahanBaku::where('nama', 'Bawang Putih')->value('id');
        $this->assertEquals(4000, BahanBakuMinimum::where('bahan_baku_id', $bawang)->value('minimum'));
        $this->assertSame(1, BahanBakuMinimum::count(), 'tebakan yang ditolak menjadi bahan tidak dikenal');
    }

    public function test_impor_master_nama_mirip_yang_sudah_ada_ditanyakan(): void
    {
        $judul = 'nama,kategori,satuan_dasar,satuan_beli,isi_per_satuan_beli,toleransi_persen';
        $file = fn () => $this->csv($judul, ['Beras Jepnag,bahan pokok,GR,Kilo,1000,', 'SAUS TIRAM,BUMBU,ml,ml,1,']);

        $pratinjau = $this->pratinjau('bahan-baku', $file());
        $baris = $pratinjau->viewData('baris')->keyBy('baris');

        $this->assertSame('cek', $baris[2]['status']);
        $this->assertTrue($pratinjau->viewData('tebakan')[0]['ada']);
        $this->assertSame('siap', $baris[3]['status']);
        $this->assertContains('SAUS TIRAM → Saus Tiram', $baris[3]['betul']);
        $pratinjau->assertSee('yang sudah ada. Maksudnya itu?');

        $kunci = $pratinjau->viewData('tebakan')[0]['kunci'];
        $this->simpan('bahan-baku', $pratinjau, [$kunci => PembersihImpor::TOLAK])->assertSessionHas('sukses');

        $baru = BahanBaku::where('nama', 'Beras Jepnag')->sole();
        $this->assertSame('gram', $baru->satuanDasar->nama);
        $this->assertSame('kg', $baru->satuanBeli->nama);
        $this->assertSame('Bahan Pokok', $baru->kategori->nama);
        $this->assertSame('Bumbu', BahanBaku::where('nama', 'Saus Tiram')->sole()->kategori->nama);
        $this->assertSame(['gram', 'kg', 'ml'], Satuan::orderBy('nama')->pluck('nama')->all(), 'Kilo/GR dikenali sebagai kg/gram; hanya ml yang baru');
    }

    public function test_tebakan_resep_menahan_seluruh_menu(): void
    {
        $pratinjau = $this->pratinjau('resep', $this->csv('menu,kategori_menu,bahan,takaran,satuan,berlaku_sejak,catatan', [
            'shoyu ramen,ramen,Mie Ramen,150,,,',
            'shoyu ramen,ramen,Brs Jepang,20,,,',
        ]));
        $baris = $pratinjau->viewData('baris')->keyBy('baris');

        $this->assertSame('cek', $baris[2]['status']);
        $this->assertSame('cek', $baris[3]['status']);
        $this->assertSame(['Ikut menunggu jawaban di baris 3, karena baris-baris ini disimpan sebagai satu catatan. Jawab pertanyaan di baris itu, lalu baris ini ikut tersimpan.'], $baris[2]['pesan']);

        $this->simpan('resep', $pratinjau)->assertSessionHas('galat');
        $this->assertDatabaseMissing('menu', ['nama' => 'Shoyu Ramen']);
    }
}
