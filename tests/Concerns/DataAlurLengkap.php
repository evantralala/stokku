<?php

namespace Tests\Concerns;

use App\Models\BahanBaku;
use App\Models\BahanBakuMinimum;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\KategoriMenu;
use App\Models\Menu;
use App\Models\Permintaan;
use App\Models\Resep;
use App\Models\Satuan;
use App\Models\User;
use App\Services\BarangKeluarService;
use App\Services\BarangMasukService;
use App\Services\KirimanService;
use App\Services\OpnameService;
use App\Services\PenjualanService;
use App\Services\PersetujuanService;
use App\Services\TambahStokService;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Support\Facades\DB;

/** Data hasil alur lengkap (permintaan, kiriman, terima, barang masuk/rusak, penjualan, tambah stok, opname) untuk semua peran. */
trait DataAlurLengkap
{
    /** @return array<string, mixed> data hasil alur lengkap untuk semua peran */
    protected function siapkanData(): array
    {
        $this->withoutVite();
        $this->travelTo(now()->setTime(10, 0));
        $this->seed(PengaturanSeeder::class);
        $au = User::factory()->adminUtama()->create();
        $gp = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $jbr = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $kts = Cabang::create(['kode' => 'KNG', 'nama' => 'Cabang Kenanga', 'jenis' => Cabang::JENIS_CABANG]);
        $pusat = User::factory()->adminPusat($gp->id)->create();
        $cab = User::factory()->adminCabang($jbr->id)->create();
        $cab2 = User::factory()->adminCabang($kts->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $pokok = Kategori::create(['nama' => 'Bahan pokok', 'urutan' => 1]);
        $bahan = fn (string $nama, array $lain = []) => BahanBaku::create($lain + [
            'nama' => $nama, 'kategori_id' => $pokok->id, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5, 'aktif' => true,
        ]);
        $daftarBahan = [$bahan('Beras Jepang'), $bahan('Mie ramen'), $bahan('Tahu')];
        $ramen = KategoriMenu::create(['nama' => 'Ramen', 'urutan' => 1]);
        $menu = Menu::create(['nama' => 'Shoyu Ramen', 'kategori_menu_id' => $ramen->id]);
        Resep::create(['menu_id' => $menu->id, 'bahan_baku_id' => $daftarBahan[1]->id, 'jumlah' => 150, 'berlaku_sejak' => now()->subDay()->toDateString()]);

        foreach ([$gp, $jbr, $kts] as $lokasi) {
            foreach ($daftarBahan as $i => $b) {
                BahanBakuMinimum::create(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $b->id, 'minimum' => 2000]);
                if ($i < 2 || $lokasi->id === $gp->id) {
                    DB::table('stok_saldo')->insert(['cabang_id' => $lokasi->id, 'bahan_baku_id' => $b->id, 'saldo' => $lokasi->id === $gp->id ? 20000 : 1500, 'diperbarui_pada' => now()]);
                }
            }
        }

        $beras = $daftarBahan[0];
        $tanggal = now()->toDateString();

        $this->actingAs($cab)->get(route('cabang.stok'))->assertOk();
        $isi = $this->get(route('cabang.stok.isi'))->assertOk();
        $baris = collect($isi->viewData('daftar'))->take(3)->mapWithKeys(fn ($r) => [$r['bahan']->id => [
            'pilih' => '1', 'bahan_baku_id' => $r['bahan']->id, 'qty' => '1',
        ]])->all();
        $this->post(route('cabang.stok.isi.simpan'), ['baris' => $baris])->assertSessionHas('sukses');
        $permintaan = Permintaan::latest('id')->firstOrFail();
        app(PersetujuanService::class)->putuskanPermintaan($permintaan, $au,
            $permintaan->detail->mapWithKeys(fn ($d) => [$d->id => ['aksi' => 'setujui', 'qty' => '1']])->all());
        $kiriman = app(KirimanService::class)->kirimYangAda($permintaan->fresh(), $gp, $pusat);
        app(KirimanService::class)->terimaSesuai($kiriman, $cab);
        app(KirimanService::class)->kirimLangsung($jbr, $kts, $cab, [$beras->id => 1], 'Titip');

        app(BarangMasukService::class)->catat($pusat, $gp, ['tanggal' => $tanggal, 'baris' => [['bahan_baku_id' => $beras->id, 'qty' => 5]]]);
        app(BarangKeluarService::class)->catat($cab, $jbr, ['tanggal' => $tanggal, 'baris' => [[
            'bahan_baku_id' => $beras->id, 'qty' => 0.5, 'alasan' => 'Basah kena air',
        ]]]);
        app(PenjualanService::class)->catat($cab, $jbr, $tanggal, [$menu->id => 4]);
        app(TambahStokService::class)->tambah($pusat, $gp, $beras, 2, null);
        app(OpnameService::class)->mulai($cab2, $kts);

        return compact('au', 'pusat', 'cab', 'cab2', 'gp', 'jbr', 'kts', 'beras');
    }
}
