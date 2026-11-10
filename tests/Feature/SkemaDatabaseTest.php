<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Pengaturan;
use App\Models\Resep;
use App\Models\Satuan;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SkemaDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function buatBahan(): BahanBaku
    {
        $gram = Satuan::create(['nama' => 'gram']);
        $kg   = Satuan::create(['nama' => 'kg']);

        return BahanBaku::create([
            'nama'                => 'Bahan Uji',
            'kategori_id'         => Kategori::create(['nama' => 'Kategori Uji'])->id,
            'satuan_dasar_id'     => $gram->id,
            'satuan_beli_id'      => $kg->id,
            'isi_per_satuan_beli' => 1000,
            'toleransi_persen'    => 5,
        ]);
    }

    public function test_bahan_baku_terhubung_ke_satuan(): void
    {
        $bahan = $this->buatBahan()->load('satuanDasar', 'satuanBeli');

        $this->assertSame('gram', $bahan->satuanDasar->nama);
        $this->assertSame('kg', $bahan->satuanBeli->nama);
        $this->assertSame(2000.0, $bahan->keSatuanDasar(2));
    }

    public function test_lokasi_yang_punya_saldo_stok_tidak_bisa_dihapus(): void
    {
        $lokasi = Cabang::create([
            'kode'              => 'LOKASI-01',
            'nama'              => 'Lokasi dengan kode panjang',
            'jenis'             => Cabang::JENIS_CABANG,
        ]);

        DB::table('stok_saldo')->insert([
            'cabang_id' => $lokasi->id, 'bahan_baku_id' => $this->buatBahan()->id, 'saldo' => 500, 'diperbarui_pada' => now(),
        ]);

        $this->expectException(QueryException::class);

        $lokasi->delete();
    }

    public function test_menu_memakai_kategori_menu_dan_resep_menyalin_satuan(): void
    {
        $bahan = $this->buatBahan();

        $menu = Menu::create([
            'nama'             => 'Menu Uji',
            'kategori_menu_id' => KategoriMenu::create(['nama' => 'Kategori Menu Uji'])->id,
        ]);

        Resep::create([
            'menu_id'       => $menu->id,
            'bahan_baku_id' => $bahan->id,
            'jumlah'        => 150,
            'berlaku_sejak' => now()->subDay()->toDateString(),
        ]);

        $resep = $menu->resepBerlaku();

        $this->assertCount(1, $resep);
        $this->assertSame('gram', $resep->first()->bahanBaku->satuanDasar->nama);
        $this->assertSame('Kategori Menu Uji', $menu->kategoriMenu->nama);
    }

    public function test_seeder_pengaturan_tidak_menimpa_nilai_yang_sudah_diubah(): void
    {
        $this->seed(PengaturanSeeder::class);
        Pengaturan::simpan('batas_jam_persetujuan', '12');

        $this->seed(PengaturanSeeder::class);

        $this->assertSame('12', Pengaturan::ambil('batas_jam_persetujuan'));
        $this->assertSame('jam', Pengaturan::where('kunci', 'batas_jam_persetujuan')->value('satuan'));
        $this->assertSame(6, Pengaturan::count());
    }

    public function test_berkas_sql_impor_sama_dengan_migration_dan_tabel_aplikasi(): void
    {
        $sql = file_get_contents(base_path('database_stok.sql'));

        foreach (glob(database_path('migrations/*.php')) as $berkas) {
            $this->assertStringContainsString("'".basename($berkas, '.php')."'", $sql);
        }

        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $tabel) {
            if ($tabel !== 'migrations' && ! str_starts_with($tabel, 'sqlite_')) {
                $this->assertStringContainsString("create table `{$tabel}`", $sql);
            }
        }

        $this->assertStringNotContainsString('kedaluwarsa`', $sql);
        $this->assertStringNotContainsString('create table `stok_batch`', $sql);
    }
}
