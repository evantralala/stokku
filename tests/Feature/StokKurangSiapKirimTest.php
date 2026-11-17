<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Pengaturan;
use App\Models\Permintaan;
use App\Models\Satuan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StokKurangSiapKirimTest extends TestCase
{
    use RefreshDatabase;

    public function test_barang_stok_kurang_tampil_per_barang_dengan_jumlahnya(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        Pengaturan::create(['kunci' => 'batas_jam_persetujuan', 'nilai' => '24']);

        $adminUtama = User::factory()->adminUtama()->create();
        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        User::factory()->adminPusat($pusat->id)->create();
        $adminCabang = User::factory()->adminCabang($cabang->id)->create();

        $gram = Satuan::create(['nama' => 'gram']);
        $kg = Satuan::create(['nama' => 'kg']);
        $kategori = Kategori::create(['nama' => 'Kering'])->id;
        $bahan = fn (string $nama) => BahanBaku::create([
            'nama' => $nama, 'kategori_id' => $kategori, 'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $kg->id,
            'isi_per_satuan_beli' => 1000, 'toleransi_persen' => 5,
        ]);
        $nori = $bahan('Nori');
        $garam = $bahan('Garam');
        $cabai = $bahan('Bubuk Cabai');

        DB::table('stok_saldo')->insert([
            ['cabang_id' => $pusat->id, 'bahan_baku_id' => $nori->id, 'saldo' => 10000, 'diperbarui_pada' => now()],
            ['cabang_id' => $pusat->id, 'bahan_baku_id' => $garam->id, 'saldo' => 500, 'diperbarui_pada' => now()],
            ['cabang_id' => $pusat->id, 'bahan_baku_id' => $cabai->id, 'saldo' => -300, 'diperbarui_pada' => now()],
        ]);

        $this->actingAs($adminCabang)->post(route('cabang.permintaan.simpan'), ['baris' => [
            ['bahan_baku_id' => $nori->id, 'qty' => '4'],
            ['bahan_baku_id' => $garam->id, 'qty' => '2'],
            ['bahan_baku_id' => $cabai->id, 'qty' => '1'],
        ]]);
        $permintaan = Permintaan::first();
        $this->actingAs($adminUtama)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
            'keputusan' => $permintaan->detail->mapWithKeys(fn ($d) => [$d->id => ['aksi' => 'setujui', 'qty' => $d->bahan_baku_id === $nori->id ? '4' : ($d->bahan_baku_id === $garam->id ? '2' : '1')]])->all(),
        ]);

        $this->actingAs($adminUtama)->get(route('admin-utama.kiriman'))
            ->assertOk()
            ->assertSee('id="stok-kurang-'.$permintaan->id.'"', false)
            ->assertSeeInOrder(['Stok kurang', '2', 'barang',
                'Bubuk Cabai', 'Kurang 1 kg', 'Perlu 1 kg · Stok 0 kg',
                'Garam', 'Kurang 1,5 kg', 'Perlu 2 kg · Stok 0,5 kg'])
            ->assertDontSee('<span class="stok-kurang-nama">Nori</span>', false);
    }
}
