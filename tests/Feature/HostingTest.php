<?php

namespace Tests\Feature;

use App\Models\BahanBaku;
use App\Models\Cabang;
use App\Models\Kategori;
use App\Models\LogPerubahan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\CadanganService;
use App\Services\KontrolSistemService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Siap shared hosting: foto tanpa symlink, cadangan database untuk tutup buku, dan tugas terjadwal. */
class HostingTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUtama;
    private Cabang $cabang;
    private BahanBaku $nori;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUtama = User::factory()->adminUtama()->create();
        $this->cabang = Cabang::create(['kode' => 'JTI', 'nama' => 'Cabang Jati', 'jenis' => Cabang::JENIS_CABANG]);
        $gram = Satuan::create(['nama' => 'gram']);
        $this->nori = BahanBaku::create([
            'nama' => 'Nori', 'kategori_id' => Kategori::create(['nama' => 'Kering'])->id,
            'satuan_dasar_id' => $gram->id, 'satuan_beli_id' => $gram->id, 'isi_per_satuan_beli' => 1, 'toleransi_persen' => 5,
        ]);
    }

    public function test_cadangan_berisi_skema_dan_data_tanpa_tabel_sementara(): void
    {
        Storage::fake(CadanganService::DISK);

        $nama = app(CadanganService::class)->buat($this->adminUtama);
        $isi = gzdecode(Storage::disk(CadanganService::DISK)->get(CadanganService::FOLDER.'/'.$nama));

        $this->assertMatchesRegularExpression('/^cadangan-\d{8}-\d{6}\.sql\.gz$/', $nama);
        $this->assertStringContainsString('CREATE TABLE "bahan_baku"', $isi);
        $this->assertStringContainsString("'Nori'", $isi);
        $this->assertStringNotContainsString('CREATE TABLE "sessions"', $isi);
        $this->assertSame('cadangan.buat', LogPerubahan::latest('id')->first()->aksi);
    }

    public function test_hanya_permintaan_dan_tolak_otomatis_yang_terjadwal(): void
    {
        $perintah = collect(app(Schedule::class)->events())->map->command->implode(' ');

        $this->assertStringContainsString('permintaan:buat-otomatis', $perintah);
        $this->assertStringContainsString('persetujuan:tolak-otomatis', $perintah);
        $this->assertStringNotContainsString('cadangan:buat', $perintah);
        $this->assertStringNotContainsString('jadwal:detak', $perintah);
    }
}
