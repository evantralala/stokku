<?php

namespace Tests\Feature;

use App\Mail\LaporanHarianMail;
use App\Models\EmailLaporan;
use App\Services\LaporanHarianService;
use App\Services\PengosongTransaksiService;
use App\Support\DaftarLaporanEmail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\DataAlurLengkap;
use Tests\TestCase;

/** Email laporan harian pukul 23.59: pengaturan per akun di menu Akun, isi per peran, dan pengiriman terjadwal. */
class EmailLaporanTest extends TestCase
{
    use DataAlurLengkap;
    use RefreshDatabase;

    private array $d;

    protected function setUp(): void
    {
        parent::setUp();

        $this->d = $this->siapkanData();
        Mail::fake();
    }

    /** @return list<string> nama lembar di lampiran Excel */
    private function lembar(array $isi): array
    {
        $berkas = tempnam(sys_get_temp_dir(), 'lap').'.xlsx';
        file_put_contents($berkas, $isi['lampiran']);

        return IOFactory::load($berkas)->getSheetNames();
    }

    public function test_menu_akun_menampilkan_pilihan_sesuai_peran_dengan_centang_bawaan(): void
    {
        $this->actingAs($this->d['cab'])->get(route('akun'))
            ->assertOk()
            ->assertSee('Email Laporan Harian')
            ->assertSee('pukul 23.59')
            ->assertSee('value="'.DaftarLaporanEmail::STOK.'" checked', false)
            ->assertSee('Penjualan hari ini')
            ->assertDontSee('Menunggu persetujuan')
            ->assertDontSee('Kirim Sekarang');

        $this->actingAs($this->d['au'])->get(route('akun'))
            ->assertSee('value="'.DaftarLaporanEmail::MENUNGGU.'" checked', false)
            ->assertDontSee('Barang diterima hari ini');
    }

    public function test_simpan_pengaturan_memisah_banyak_email_dan_membuang_laporan_peran_lain(): void
    {
        $this->actingAs($this->d['cab'])->put(route('akun.email'), [
            'aktif'       => '1',
            'alamat_teks' => "Bos@Contoh.com, gudang@contoh.com\n bos@contoh.com ; kasir@contoh.com",
            'laporan'     => [DaftarLaporanEmail::STOK, DaftarLaporanEmail::RUSAK],
        ])->assertSessionHasNoErrors()->assertRedirect(route('akun').'#email-laporan');

        $email = EmailLaporan::sole();
        $this->assertTrue($email->aktif);
        $this->assertSame(['bos@contoh.com', 'gudang@contoh.com', 'kasir@contoh.com'], $email->alamat);
        $this->assertSame([DaftarLaporanEmail::STOK, DaftarLaporanEmail::RUSAK], $email->laporan);

        $this->put(route('akun.email'), ['aktif' => '1', 'alamat_teks' => 'a@b.com', 'laporan' => [DaftarLaporanEmail::MENUNGGU]])
            ->assertSessionHasErrors('laporan.0');
    }

    public function test_validasi_email_tujuan_dan_laporan_saat_dinyalakan(): void
    {
        $this->actingAs($this->d['cab'])->from(route('akun'))
            ->put(route('akun.email'), ['aktif' => '1', 'alamat_teks' => '', 'laporan' => []])
            ->assertSessionHasErrors(['alamat_teks' => 'Isi minimal satu email tujuan.', 'laporan' => 'Centang minimal satu laporan.']);

        $this->put(route('akun.email'), ['aktif' => '1', 'alamat_teks' => "bos@contoh.com\nbukan-email", 'laporan' => [DaftarLaporanEmail::STOK]])
            ->assertSessionHasErrors(['alamat_teks' => 'Alamat "bukan-email" bukan email yang benar.']);

        $this->put(route('akun.email'), ['alamat_teks' => '', 'laporan' => []])->assertSessionHasNoErrors();
        $this->assertFalse(EmailLaporan::sole()->aktif);
    }

    public function test_isi_laporan_per_peran_ringkasan_dan_lembar_excel(): void
    {
        $layanan = app(LaporanHarianService::class);

        $cabang = $layanan->susun($this->d['cab'], DaftarLaporanEmail::bawaan('admin_cabang') + [1 => DaftarLaporanEmail::PENJUALAN, 2 => DaftarLaporanEmail::DITERIMA, 3 => DaftarLaporanEmail::RUSAK], now());
        $this->assertStringContainsString('Laporan Harian Cabang Jati', $cabang['subjek']);
        $this->assertSame(['Stok', 'Penjualan', 'Kiriman Masuk', 'Barang Rusak'], $this->lembar($cabang));
        $this->assertSame('4 porsi dari 1 menu', $cabang['ringkasan'][1]['baris'][0]);
        $this->assertSame('1 laporan', $cabang['ringkasan'][3]['baris'][0]);

        $pusat = $layanan->susun($this->d['pusat'], [DaftarLaporanEmail::STOK, DaftarLaporanEmail::KIRIMAN, DaftarLaporanEmail::MASUK], now());
        $this->assertSame(['Stok', 'Kiriman Keluar', 'Kiriman Masuk', 'Barang Masuk'], $this->lembar($pusat));
        $this->assertSame('1 dokumen, 1 baris barang', $pusat['ringkasan'][2]['baris'][0]);

        $utama = $layanan->susun($this->d['au'], array_keys(DaftarLaporanEmail::pilihan('admin_utama')), now());
        $this->assertStringContainsString('Semua Lokasi', $utama['subjek']);
        $this->assertSame(['Stok Menipis', 'Penjualan', 'Menunggu', 'Stok GP', 'Stok JTI', 'Stok KNG', 'Barang Rusak', 'Selisih Opname'], $this->lembar($utama));
        $this->assertSame(['Cabang Jati: 4 porsi', 'Cabang Kenanga: belum ada penjualan'], $utama['ringkasan'][1]['baris']);
    }

    public function test_perintah_harian_mengirim_sekali_ke_akun_yang_menyala(): void
    {
        EmailLaporan::create(['user_id' => $this->d['cab']->id, 'aktif' => true, 'alamat' => ['bos@contoh.com', 'gudang@contoh.com'], 'laporan' => [DaftarLaporanEmail::STOK]]);
        EmailLaporan::create(['user_id' => $this->d['pusat']->id, 'aktif' => false, 'alamat' => ['pusat@contoh.com'], 'laporan' => [DaftarLaporanEmail::STOK]]);
        EmailLaporan::create(['user_id' => $this->d['cab2']->id, 'aktif' => true, 'alamat' => ['kts@contoh.com'], 'laporan' => [DaftarLaporanEmail::STOK]]);
        $this->d['cab2']->update(['aktif' => false]);

        $this->artisan('laporan:kirim-harian')->expectsOutput('Terkirim: 1 akun. Gagal: 0 akun.')->assertSuccessful();

        Mail::assertSent(LaporanHarianMail::class, 1);
        Mail::assertSent(LaporanHarianMail::class, fn (LaporanHarianMail $m) => $m->hasTo('bos@contoh.com') && $m->hasTo('gudang@contoh.com')
            && str_contains($m->subjek, 'Cabang Jati') && count($m->attachments()) === 1);
        $this->assertTrue(EmailLaporan::where('user_id', $this->d['cab']->id)->value('terakhir_dikirim') !== null);

        $this->artisan('laporan:kirim-harian')->expectsOutput('Terkirim: 0 akun. Gagal: 0 akun.');
        $this->artisan('laporan:kirim-harian --ulang')->expectsOutput('Terkirim: 1 akun. Gagal: 0 akun.');
        Mail::assertSent(LaporanHarianMail::class, 2);
    }

    public function test_susulan_mengirim_laporan_kemarin_yang_belum_terkirim_tanpa_menahan_malam_ini(): void
    {
        EmailLaporan::create(['user_id' => $this->d['cab']->id, 'aktif' => true, 'alamat' => ['bos@contoh.com'], 'laporan' => [DaftarLaporanEmail::STOK]]);

        $this->artisan('laporan:kirim-harian --susulan')->expectsOutput('Terkirim: 1 akun. Gagal: 0 akun.');
        $this->assertSame(now()->subDay()->toDateString(), EmailLaporan::sole()->terakhir_dikirim->toDateString());

        $this->artisan('laporan:kirim-harian --susulan')->expectsOutput('Terkirim: 0 akun. Gagal: 0 akun.');
        $this->artisan('laporan:kirim-harian')->expectsOutput('Terkirim: 1 akun. Gagal: 0 akun.');
    }

    public function test_kirim_sekarang_dari_menu_akun_tidak_menahan_kiriman_terjadwal(): void
    {
        $this->actingAs($this->d['pusat'])->post(route('akun.email.kirim'))->assertSessionHas('galat', 'Simpan pengaturan email dulu, lalu coba kirim.');

        $this->put(route('akun.email'), ['aktif' => '1', 'alamat_teks' => 'pusat@contoh.com', 'laporan' => [DaftarLaporanEmail::STOK]]);
        $this->get(route('akun'))->assertSee('Kirim Sekarang');

        $this->post(route('akun.email.kirim'))->assertSessionHas('sukses', 'Laporan hari ini dikirim ke pusat@contoh.com.');
        Mail::assertSent(LaporanHarianMail::class, fn (LaporanHarianMail $m) => $m->hasTo('pusat@contoh.com'));
        $this->assertNull(EmailLaporan::sole()->terakhir_dikirim);
    }

    public function test_dijadwalkan_pukul_23_59_dan_tetap_ada_setelah_tutup_buku(): void
    {
        $jadwal = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'laporan:kirim-harian'));

        $this->assertSame(['59 23 * * *', '10 0 * * *'], $jadwal->pluck('expression')->values()->all());
        $this->assertNotContains('email_laporan', app(PengosongTransaksiService::class)->tabelTransaksi());
    }
}
