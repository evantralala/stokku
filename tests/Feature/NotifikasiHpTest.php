<?php

namespace Tests\Feature;

use App\Models\LanggananPush;
use App\Models\User;
use App\Services\NotifikasiService;
use App\Services\PushService;
use App\Support\KriptoPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifikasiHpTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private function nyalakan(): array
    {
        $kunci = KriptoPush::buatKunci();
        config(['stokku.push.publik' => $kunci['publik'], 'stokku.push.privat' => $kunci['privat'], 'stokku.push.subjek' => 'mailto:uji@stokku.test']);

        return $kunci;
    }

    /** Langganan browser tiruan dengan kunci P-256 sungguhan. */
    private function dataLangganan(string $endpoint = self::ENDPOINT): array
    {
        $ua = KriptoPush::buatKunci();

        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $ua['publik'], 'auth' => KriptoPush::b64(random_bytes(16))]];
    }

    public function test_enkripsi_sesuai_contoh_rfc_8291(): void
    {
        $hasil = KriptoPush::enkripsi(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            [
                'publik' => KriptoPush::dariB64('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
                'privat' => KriptoPush::dariB64('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            ],
            KriptoPush::dariB64('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            KriptoPush::b64($hasil),
        );
    }

    public function test_header_vapid_bertanda_tangan_es256_yang_sah(): void
    {
        $kunci = KriptoPush::buatKunci();
        $header = KriptoPush::headerVapid(self::ENDPOINT.'?x=1', $kunci['publik'], $kunci['privat'], 'mailto:uji@stokku.test', 1900000000);

        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k='.preg_quote($kunci['publik'], '/').'$/', $header);
        [$kepala, $klaim, $tanda] = explode('.', substr(explode(', k=', $header)[0], 8));
        $this->assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1900000000, 'sub' => 'mailto:uji@stokku.test'], json_decode(KriptoPush::dariB64($klaim), true));

        $mentah = KriptoPush::dariB64($tanda);
        $this->assertSame(64, strlen($mentah));
        $bilangan = fn (string $b) => (ord(ltrim($b, "\0")[0]) & 0x80 ? "\0" : '').ltrim($b, "\0");
        [$r, $s] = [$bilangan(substr($mentah, 0, 32)), $bilangan(substr($mentah, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').KriptoPush::dariB64($kunci['publik'])), 64, "\n")."-----END PUBLIC KEY-----\n";

        $this->assertSame(1, openssl_verify($kepala.'.'.$klaim, $der, $pem, OPENSSL_ALGO_SHA256));
    }

    public function test_tanpa_kunci_notifikasi_hp_mati(): void
    {
        config(['stokku.push.publik' => null, 'stokku.push.privat' => null]);
        $admin = User::factory()->adminUtama()->create();

        $this->actingAs($admin)->get(route('akun'))->assertOk()
            ->assertDontSee('name="push-kunci"', false)->assertDontSee('Notifikasi HP')->assertDontSee('data-push-ajak', false);
        $this->postJson(route('push.simpan'), $this->dataLangganan())->assertNotFound();
        $this->assertSame(0, app(PushService::class)->kirim([$admin->id], 'Uji'));
    }

    public function test_langganan_disimpan_diperbarui_dan_dihapus_per_pemilik(): void
    {
        $kunci = $this->nyalakan();
        $admin = User::factory()->adminUtama()->create();
        $lain = User::factory()->adminUtama()->create();

        $this->actingAs($admin)->get(route('akun'))->assertOk()
            ->assertSee('<meta name="push-kunci" content="'.$kunci['publik'].'">', false)
            ->assertSee('Notifikasi HP')->assertSee('data-push-ajak', false);

        $data = $this->dataLangganan();
        $this->postJson(route('push.simpan'), $data)->assertOk()->assertJson(['aktif' => true]);
        $this->postJson(route('push.simpan'), $data, ['User-Agent' => 'HP Uji'])->assertOk();
        $this->assertSame(1, LanggananPush::count());
        $this->assertSame('HP Uji', LanggananPush::first()->perangkat);

        $this->actingAs($lain)->deleteJson(route('push.hapus'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(1, LanggananPush::count());

        $this->actingAs($admin)->deleteJson(route('push.hapus'), ['endpoint' => self::ENDPOINT])->assertOk()->assertJson(['aktif' => false]);
        $this->assertSame(0, LanggananPush::count());
    }

    public function test_alamat_push_harus_https_dan_kunci_wajib(): void
    {
        $this->nyalakan();
        $admin = User::factory()->adminUtama()->create();

        $this->actingAs($admin)->postJson(route('push.simpan'), ['endpoint' => 'http://contoh.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        $this->postJson(route('push.simpan'), ['endpoint' => self::ENDPOINT])->assertUnprocessable()->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
        auth()->logout();
        $this->postJson(route('push.simpan'), $this->dataLangganan())->assertUnauthorized();
    }

    public function test_notifikasi_baru_dikirim_ke_hp_penerima_dan_langganan_mati_dihapus(): void
    {
        $this->nyalakan();
        $admin = User::factory()->adminUtama()->create();
        $bukan = User::factory()->adminUtama()->create(['aktif' => false]);
        app(PushService::class)->langgan($admin, $this->dataLangganan(), 'HP');
        app(PushService::class)->langgan($admin, $this->dataLangganan('https://updates.push.services.mozilla.com/wpush/v2/mati'), 'Laptop');
        app(PushService::class)->langgan($bukan, $this->dataLangganan('https://fcm.googleapis.com/fcm/send/bukan'), 'HP');

        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);

        app(NotifikasiService::class)->keAdminUtama('Permintaan baru', 'Cabang Jati meminta 3 barang', '/admin-utama/persetujuan');
        Http::assertNothingSent();

        $this->app->terminate();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === self::ENDPOINT
            && $r->method() === 'POST'
            && str_starts_with($r->header('Authorization')[0], 'vapid t=')
            && $r->header('Content-Encoding')[0] === 'aes128gcm'
            && $r->header('TTL')[0] === (string) PushService::TTL_DETIK
            && strlen($r->body()) > 86);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'bukan'));

        $this->assertSame(['HP', 'HP'], LanggananPush::orderBy('id')->pluck('perangkat')->all());
        $this->assertNotNull(LanggananPush::where('endpoint', self::ENDPOINT)->value('terakhir_dikirim'));
    }

    public function test_gagal_kirim_ke_layanan_push_tidak_menggagalkan_apa_pun(): void
    {
        $this->nyalakan();
        $admin = User::factory()->adminUtama()->create();
        app(PushService::class)->langgan($admin, $this->dataLangganan(), 'HP');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('putus'));

        $this->assertSame(0, app(PushService::class)->kirim([$admin], 'Uji'));
        $this->assertSame(1, LanggananPush::count());
    }

    public function test_perintah_buat_kunci_menulis_env_sekali(): void
    {
        $folder = sys_get_temp_dir().'/push-uji-'.uniqid();
        mkdir($folder);
        file_put_contents($folder.'/.env', "APP_NAME=Uji\nVAPID_PUBLIC_KEY=\n");
        $this->app->useEnvironmentPath($folder);

        $this->artisan('push:buat-kunci')->assertSuccessful();
        $isi = file_get_contents($folder.'/.env');
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[\w-]{87}$/m', $isi);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[\w-]{43}$/m', $isi);
        $this->assertStringContainsString('APP_NAME=Uji', $isi);

        $this->artisan('push:buat-kunci')->assertSuccessful();
        $this->assertSame($isi, file_get_contents($folder.'/.env'));

        $this->artisan('push:buat-kunci --ganti')->assertSuccessful();
        $this->assertNotSame($isi, file_get_contents($folder.'/.env'));
        unlink($folder.'/.env');
        rmdir($folder);
    }
}
