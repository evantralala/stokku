<?php

namespace App\Services;

use App\Models\LanggananPush;
use App\Models\User;
use App\Support\Identitas;
use App\Support\KriptoPush;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notifikasi HP lewat Web Push: menyimpan langganan per perangkat dan mengirim pesan ke layanan push browser
 * (Google, Mozilla, Apple). Isi pesan dienkripsi untuk tiap langganan; langganan yang sudah tidak berlaku dihapus.
 */
class PushService
{
    public const TTL_DETIK = 86400;

    public static function aktif(): bool
    {
        return filled(config('stokku.push.publik')) && filled(config('stokku.push.privat'));
    }

    /** @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $data */
    public function langgan(User $pengguna, array $data, ?string $perangkat): LanggananPush
    {
        return LanggananPush::updateOrCreate(
            ['endpoint_hash' => LanggananPush::hash($data['endpoint'])],
            [
                'user_id'   => $pengguna->id,
                'endpoint'  => $data['endpoint'],
                'p256dh'    => $data['keys']['p256dh'],
                'auth'      => $data['keys']['auth'],
                'perangkat' => $perangkat === null ? null : Str::limit($perangkat, 147),
            ],
        );
    }

    public function berhenti(User $pengguna, string $endpoint): void
    {
        LanggananPush::where('endpoint_hash', LanggananPush::hash($endpoint))->where('user_id', $pengguna->id)->delete();
    }

    /**
     * Kirim satu pesan ke semua perangkat milik para penerima. Gagal kirim tidak menggagalkan apa pun.
     *
     * @param  Collection<int, User>|list<int>  $penerima
     * @return int jumlah perangkat yang menerima
     */
    public function kirim(Collection|array $penerima, string $judul, ?string $keterangan = null, ?string $tautan = null): int
    {
        if (! self::aktif()) {
            return 0;
        }

        $id = collect($penerima)->map(fn ($u) => $u instanceof User ? $u->id : (int) $u)->unique()->values();
        $daftar = LanggananPush::whereIn('user_id', $id)->get();
        if ($daftar->isEmpty()) {
            return 0;
        }

        $isi = json_encode([
            'judul'  => $judul,
            'isi'    => $keterangan ?? '',
            'tautan' => $tautan === null ? route('notifikasi') : url($tautan),
            'ikon'   => Identitas::logo('ikon-192.png'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $terkirim = 0;
        foreach ($daftar as $langganan) {
            $terkirim += $this->kirimSatu($langganan, $isi) ? 1 : 0;
        }

        return $terkirim;
    }

    private function kirimSatu(LanggananPush $langganan, string $isi): bool
    {
        try {
            $badan = KriptoPush::enkripsi($isi, $langganan->p256dh, $langganan->auth);
            $otorisasi = KriptoPush::headerVapid(
                $langganan->endpoint,
                config('stokku.push.publik'),
                config('stokku.push.privat'),
                config('stokku.push.subjek'),
                now()->addHours(12)->getTimestamp(),
            );

            $jawaban = Http::timeout(5)
                ->withHeaders([
                    'Authorization'    => $otorisasi,
                    'TTL'              => (string) self::TTL_DETIK,
                    'Urgency'          => 'high',
                    'Content-Encoding' => 'aes128gcm',
                ])
                ->withBody($badan, 'application/octet-stream')
                ->post($langganan->endpoint);
        } catch (Throwable $e) {
            Log::warning('Notifikasi HP gagal dikirim: '.$e->getMessage());

            return false;
        }

        if (in_array($jawaban->status(), [404, 410], true)) {
            $langganan->delete();

            return false;
        }

        if (! $jawaban->successful()) {
            Log::warning('Notifikasi HP ditolak layanan push: '.$jawaban->status());

            return false;
        }

        $langganan->forceFill(['terakhir_dikirim' => now()])->save();

        return true;
    }
}
