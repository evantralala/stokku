<?php

namespace Tests\Feature;

use App\Models\Kiriman;
use App\Models\Opname;
use App\Models\PenjualanHarian;
use App\Models\Permintaan;
use App\Models\Satuan;
use App\Models\StokMutasi;
use App\Services\PersetujuanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DataAlurLengkap;
use Tests\TestCase;

/**
 * Uji iseng alur: aksi sekali-jalan yang diulang (tombol ditekan dua kali, dua tab, tombol Kembali lalu kirim lagi).
 * Ulangan tidak boleh galat server dan tidak boleh mengubah stok atau membuat dokumen dobel.
 */
class UjiIsengAlurTest extends TestCase
{
    use DataAlurLengkap;
    use RefreshDatabase;

    private function jejak(): array
    {
        return [
            'saldo'   => DB::table('stok_saldo')->orderBy('cabang_id')->orderBy('bahan_baku_id')->pluck('saldo')->map(fn ($s) => (float) $s)->all(),
            'mutasi'  => StokMutasi::count(),
            'kiriman' => Kiriman::count(),
            'jual'    => PenjualanHarian::count(),
            'opname'  => Opname::count(),
        ];
    }

    private function ulangTanpaEfek(callable $kirim, string $nama): void
    {
        $sebelum = $this->jejak();
        $respons = $kirim();

        $this->assertLessThan(500, $respons->getStatusCode(), $nama.': '.($respons->exception?->getMessage() ?? ''));
        $this->assertSame($sebelum, $this->jejak(), $nama.' tidak boleh mengubah apa pun saat diulang');
    }

    public function test_aksi_sekali_jalan_yang_diulang_tidak_dobel_dan_tidak_galat(): void
    {
        ['au' => $au, 'pusat' => $pusat, 'cab' => $cab, 'cab2' => $cab2, 'jbr' => $jbr, 'kts' => $kts] = $this->siapkanData();
        $permintaan = Permintaan::query()->latest('id')->firstOrFail();
        $kiriman = Kiriman::query()->where('permintaan_id', $permintaan->id)->firstOrFail();

        $this->ulangTanpaEfek(fn () => $this->actingAs($au)->post(route('admin-utama.persetujuan.permintaan.putuskan', $permintaan), [
            'keputusan' => $permintaan->detail->mapWithKeys(fn ($d) => [$d->id => ['aksi' => PersetujuanService::AKSI_SETUJUI, 'qty' => '5']])->all(),
        ]), 'putuskan permintaan dua kali');

        $this->ulangTanpaEfek(fn () => $this->actingAs($pusat)->post(route('pusat.kiriman.simpan', $permintaan), []),
            'kirim permintaan yang sudah dikirim');

        $this->ulangTanpaEfek(fn () => $this->actingAs($cab)->post(route('cabang.terima-barang.simpan', $kiriman), [
            'terima' => $kiriman->detail->mapWithKeys(fn ($d) => [$d->id => ['kondisi' => 'baik', 'qty' => '1']])->all(),
        ]), 'terima kiriman yang sudah diterima');

        $this->ulangTanpaEfek(fn () => $this->actingAs($cab2)->post(route('cabang.opname.mulai')),
            'mulai opname saat opname sedang berjalan');

        $menu = DB::table('menu')->value('id');
        $this->ulangTanpaEfek(fn () => $this->actingAs($cab)->post(route('cabang.penjualan.simpan'), [
            'tanggal' => now()->toDateString(), 'porsi' => [$menu => '4'],
        ]), 'catat penjualan hari yang sudah dicatat');

        $satuan = Satuan::create(['nama' => 'iseng']);
        $this->actingAs($au)->delete(route('admin-utama.satuan.hapus', $satuan))->assertRedirect();
        $this->actingAs($au)->delete(route('admin-utama.satuan.hapus', $satuan->id))->assertNotFound();
        $this->actingAs($au)->get(route('admin-utama.satuan.ubah', $satuan->id))->assertNotFound();

        $this->assertNotNull($jbr);
        $this->assertNotNull($kts);
    }

    public function test_tombol_simpan_ditahan_saat_form_sedang_terkirim(): void
    {
        $skrip = (string) file_get_contents(resource_path('js/kirim-sekali.js'));
        $app = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("dataset.terkirim === '1'", $skrip);
        $this->assertLessThan(strpos($app, 'pasangLaciMenu();'), strpos($app, 'pasangTahanKirimUlang();'), 'penahan dipasang paling awal');
        $this->assertGreaterThan(strpos($app, 'pasangPopup();'), strpos($app, 'pasangTandaiTerkirim();'), 'penanda dipasang setelah popup dan konfirmasi');
    }
}
