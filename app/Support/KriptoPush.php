<?php

namespace App\Support;

use RuntimeException;

/**
 * Kriptografi Web Push tanpa pustaka tambahan, hanya ekstensi openssl:
 * - kunci VAPID P-256 (RFC 8292) dan tanda tangan JWT ES256 untuk header Authorization;
 * - enkripsi isi pesan aes128gcm (RFC 8291 + RFC 8188) untuk langganan browser.
 * Kunci disimpan sebagai base64url: publik = titik tak terkompresi 65 byte, privat = skalar 32 byte.
 */
class KriptoPush
{
    private const OID_EC = '2a8648ce3d0201';
    private const OID_P256 = '2a8648ce3d030107';
    private const RS = 4096;

    /** @return array{publik: string, privat: string} pasangan kunci VAPID baru (base64url). */
    public static function buatKunci(): array
    {
        [$publik, $privat] = self::uraikan(self::kunciBaru());

        return ['publik' => self::b64($publik), 'privat' => self::b64($privat)];
    }

    /** Header Authorization VAPID untuk alamat push (t = JWT ES256, k = kunci publik). */
    public static function headerVapid(string $endpoint, string $publikB64, string $privatB64, string $subjek, int $kedaluwarsa): string
    {
        $bagian = parse_url($endpoint);
        $aud = ($bagian['scheme'] ?? 'https').'://'.($bagian['host'] ?? '').(isset($bagian['port']) ? ':'.$bagian['port'] : '');

        $isi = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::b64(json_encode(['aud' => $aud, 'exp' => $kedaluwarsa, 'sub' => $subjek], JSON_UNESCAPED_SLASHES));

        $kunci = openssl_pkey_get_private(self::pemPrivat(self::dariB64($privatB64), self::dariB64($publikB64)));
        if ($kunci === false || ! openssl_sign($isi, $der, $kunci, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Kunci VAPID tidak valid.');
        }

        return 'vapid t='.$isi.'.'.self::b64(self::derKeMentah($der)).', k='.$publikB64;
    }

    /**
     * Enkripsi isi pesan untuk satu langganan (RFC 8291). $kunciSementara dan $garam hanya untuk uji;
     * biasanya dibuat acak setiap pesan.
     *
     * @param  array{publik: string, privat: string}|null  $kunciSementara  mentah (bukan base64url)
     */
    public static function enkripsi(string $isi, string $p256dhB64, string $authB64, ?array $kunciSementara = null, ?string $garam = null): string
    {
        $uaPublik = self::dariB64($p256dhB64);
        $rahasiaAuth = self::dariB64($authB64);
        if (strlen($uaPublik) !== 65 || $uaPublik[0] !== "\x04" || strlen($rahasiaAuth) !== 16) {
            throw new RuntimeException('Kunci langganan tidak valid.');
        }

        if ($kunciSementara === null) {
            [$asPublik, $asPrivat] = self::uraikan(self::kunciBaru());
        } else {
            [$asPublik, $asPrivat] = [$kunciSementara['publik'], $kunciSementara['privat']];
        }
        $garam ??= random_bytes(16);

        $bersama = openssl_pkey_derive(
            openssl_pkey_get_public(self::pemPublik($uaPublik)),
            openssl_pkey_get_private(self::pemPrivat($asPrivat, $asPublik)),
            32,
        );
        if ($bersama === false) {
            throw new RuntimeException('Gagal menghitung kunci bersama.');
        }

        $ikm = hash_hkdf('sha256', $bersama, 32, "WebPush: info\0".$uaPublik.$asPublik, $rahasiaAuth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $garam);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $garam);

        $sandi = openssl_encrypt($isi."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($sandi === false) {
            throw new RuntimeException('Gagal mengenkripsi pesan.');
        }

        return $garam.pack('N', self::RS).chr(65).$asPublik.$sandi.$tag;
    }

    public static function b64(string $mentah): string
    {
        return rtrim(strtr(base64_encode($mentah), '+/', '-_'), '=');
    }

    public static function dariB64(string $teks): string
    {
        return (string) base64_decode(strtr($teks, '-_', '+/'), true);
    }

    /**
     * Kunci P-256 baru. Di Windows (XAMPP) openssl butuh berkas openssl.cnf yang sering tidak ditemukan otomatis,
     * jadi bila gagal dicoba lagi dengan berkas bawaan XAMPP (dari php.exe maupun dari Apache httpd.exe).
     */
    private static function kunciBaru(): \OpenSSLAsymmetricKey
    {
        $opsi = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $kunci = openssl_pkey_new($opsi);

        $folderPhp = dirname(PHP_BINARY);
        $calon = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            $folderPhp.'/extras/ssl/openssl.cnf',
            $folderPhp.'/extras/openssl/openssl.cnf',
            dirname($folderPhp).'/apache/conf/openssl.cnf',
            dirname($folderPhp).'/conf/openssl.cnf',
            dirname($folderPhp, 2).'/php/extras/ssl/openssl.cnf',
        ]);
        foreach ($calon as $berkas) {
            if ($kunci !== false) {
                break;
            }
            if (is_file($berkas)) {
                $kunci = openssl_pkey_new($opsi + ['config' => $berkas]);
            }
        }

        if ($kunci === false) {
            throw new RuntimeException('Gagal membuat kunci P-256. Di Windows, isi OPENSSL_CONF dengan lokasi openssl.cnf (mis. C:\\xampp\\php\\extras\\ssl\\openssl.cnf).');
        }

        return $kunci;
    }

    /** @return array{0: string, 1: string} [titik publik 65 byte, skalar privat 32 byte] */
    private static function uraikan(\OpenSSLAsymmetricKey $kunci): array
    {
        $ec = openssl_pkey_get_details($kunci)['ec'];
        $isi = fn (string $v) => str_pad($v, 32, "\0", STR_PAD_LEFT);

        return ["\x04".$isi($ec['x']).$isi($ec['y']), $isi($ec['d'])];
    }

    private static function pemPublik(string $titik): string
    {
        $der = hex2bin('3059301306'.'07'.self::OID_EC.'06'.'08'.self::OID_P256.'034200').$titik;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function pemPrivat(string $skalar, string $titik): string
    {
        $der = hex2bin('30770201010420').$skalar.hex2bin('a00a0608'.self::OID_P256.'a144034200').$titik;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** Tanda tangan ECDSA DER (SEQUENCE r, s) menjadi r || s masing-masing 32 byte. */
    private static function derKeMentah(string $der): string
    {
        $pos = 2;
        $angka = [];
        for ($i = 0; $i < 2; $i++) {
            $panjang = ord($der[$pos + 1]);
            $angka[] = str_pad(ltrim(substr($der, $pos + 2, $panjang), "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $panjang;
        }

        return $angka[0].$angka[1];
    }
}
