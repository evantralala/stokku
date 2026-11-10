<?php

namespace Tests\Feature;

use App\Services\Impor\ImporExcel;
use App\Services\Impor\ImporOpnameService;
use App\Support\DokumenPdf;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Kartu angka bersatuan, kolom PDF yang dibungkus, teks pratinjau impor opname, dan contoh .env produksi. */
class PerbaikanTampilanTest extends TestCase
{
    public function test_kartu_angka_memisahkan_satuan_supaya_bisa_turun_baris(): void
    {
        $html = Blade::render('<x-kartu-angka label="Minimum" angka="1.000 pasang" ikon="peringatan" />');
        $this->assertStringContainsString('<span class="kartu-nilai-angka">1.000</span> <span class="kartu-satuan">pasang</span>', $html);

        foreach (['—', 'Ya', '12', 'Rp 1.000'] as $angka) {
            $this->assertStringNotContainsString('kartu-satuan', Blade::render('<x-kartu-angka label="A" :angka="$a" />', ['a' => $angka]));
        }
    }

    public function test_kolom_pdf_bungkus_menampilkan_teks_utuh_di_beberapa_baris(): void
    {
        $alasan = trim(str_repeat('Kemasan bocor saat diturunkan dari mobil ', 4));
        $pdf = (new DokumenPdf('Uji'))->tabel([
            ['judul' => 'No', 'lebar' => 0.1],
            ['judul' => 'Alasan', 'lebar' => 0.3, 'bungkus' => true],
            ['judul' => 'Lain', 'lebar' => 0.6],
        ], [[1, $alasan, 'x'], [2, str_repeat('y', 120), 'z']])->hasil();

        $this->assertStringNotContainsString('...', $pdf);
        $this->assertSame(4, substr_count($pdf, 'mobil'));
        $this->assertSame(4, substr_count($pdf, 'Kemasan'));
        preg_match_all('/\((y+)\) Tj/', $pdf, $y);
        $this->assertGreaterThan(1, count($y[1]));
        $this->assertSame(120, strlen(implode('', $y[1])));
    }

    public function test_teks_baris_salah_impor_opname_tidak_menyuruh_simpan_sebagian(): void
    {
        $this->assertStringContainsString('simpan yang siap dulu', (new class(app(\App\Services\LogPerubahanService::class)) extends ImporExcel {
            public function judul(): string { return 'Uji'; }
            public function kolom(): array { return []; }
            protected function kolomWajib(): array { return []; }
            protected function barisEkspor(): array { return []; }
            public function periksa(array $mentah): array { return []; }
            public function simpan(array $mentah, \App\Models\User $oleh, ?string $namaFile): array { return []; }
            public function ringkasan(array $data): array { return ['', '']; }
        })->teksBarisSalah());

        $teks = app(ImporOpnameService::class)->teksBarisSalah();
        $this->assertStringContainsString('Opname tidak dibuat', $teks);
        $this->assertStringNotContainsString('simpan yang siap dulu', $teks);
    }

    public function test_contoh_env_produksi_tanpa_tanda_kurung_sudut_di_alamat(): void
    {
        $isi = file_get_contents(base_path('.env.production.example'));

        $this->assertMatchesRegularExpression('/^APP_URL=https:\/\/[a-z0-9.-]+$/m', $isi);
        $this->assertDoesNotMatchRegularExpression('/^(APP_URL|MAIL_FROM_ADDRESS)=.*</m', $isi);
    }
}
