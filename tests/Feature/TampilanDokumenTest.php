<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\Satuan;
use App\Models\User;
use App\Services\BarangMasukService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman detail dokumen memakai kartu kepala (ikon, nomor, status, strip info) dan kotak dampak; daftar transaksi punya kartu angka. */
class TampilanDokumenTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_barang_masuk_dan_daftarnya(): void
    {
        $pusat = Cabang::create(['kode' => 'GP', 'nama' => 'Gudang Pusat', 'jenis' => Cabang::JENIS_PUSAT]);
        $admin = User::factory()->adminPusat($pusat->id)->create(['nama' => 'Rina']);
        $kg = Satuan::create(['nama' => 'kg']);
        $beras = BahanBaku::create([
            'nama' => 'Beras', 'kategori_id' => Kategori::create(['nama' => 'Pokok'])->id, 'satuan_dasar_id' => $kg->id,
            'satuan_beli_id' => $kg->id, 'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
        $dokumen = app(BarangMasukService::class)->catat($admin, $pusat, ['tanggal' => now()->toDateString(), 'baris' => [['bahan_baku_id' => $beras->id, 'qty' => 5]]]);

        $this->actingAs($admin)
            ->get(route('pusat.barang-masuk.lihat', $dokumen))
            ->assertOk()
            ->assertSeeInOrder([
                '<section class="panel kepala-dokumen nada-masuk">', $dokumen->nomor, 'Barang Masuk', 'Menunggu Persetujuan',
                '<dl class="kepala-dokumen-info">', 'Lokasi', 'Gudang Pusat', 'Dicatat', 'Rina',
                'Dampak ke Stok',
            ], false)
            ->assertDontSee('kotak-keterangan', false);

        $this->get(route('pusat.barang-masuk'))
            ->assertOk()
            ->assertSeeInOrder(['<div class="kartu-grid kartu-grid-master">', 'Dokumen Barang Masuk', 'Perlu Diperbaiki'], false);
    }
}
