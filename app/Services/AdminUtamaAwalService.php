<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menjamin selalu ada minimal satu akun Admin Utama aktif (dipakai seeder).
 *
 * Data akun dari config stokku.admin_utama_awal (ADMIN_UTAMA_NAMA, ADMIN_UTAMA_USERNAME, ADMIN_UTAMA_PASSWORD).
 * Bila nama atau username kosong dipakai NAMA_BAWAAN / USERNAME_BAWAAN; bila password kosong (atau kurang dari 8 karakter)
 * dibuat password acak dan dikembalikan supaya bisa ditampilkan sekali di layar.
 * Bila username itu sudah dipakai akun lain, akun itu dijadikan Admin Utama aktif dengan password baru.
 */
class AdminUtamaAwalService
{
    public const NAMA_BAWAAN = 'Admin Utama';
    public const USERNAME_BAWAAN = 'admin';
    public const PANJANG_PASSWORD_ACAK = 12;
    private const PANJANG_PASSWORD_MIN = 8;

    /**
     * @return array{username: string, dipulihkan: bool, password_dibuat: ?string}|null null bila sudah ada Admin Utama aktif
     */
    public function pastikanAda(): ?array
    {
        if (User::query()->where('role', User::ROLE_ADMIN_UTAMA)->where('aktif', true)->exists()) {
            return null;
        }

        $data = config('stokku.admin_utama_awal', []);
        $nama = trim((string) ($data['nama'] ?? '')) ?: self::NAMA_BAWAAN;
        $username = User::rapikanUsername((string) ($data['username'] ?? ''));
        $username = preg_match(User::POLA_USERNAME, $username) === 1 ? $username : self::USERNAME_BAWAAN;
        $password = (string) ($data['password'] ?? '');
        $passwordDibuat = null;

        if (mb_strlen($password) < self::PANJANG_PASSWORD_MIN) {
            $password = $passwordDibuat = Str::password(self::PANJANG_PASSWORD_ACAK, symbols: false);
        }

        $ada = User::query()->where('username', $username)->first();

        DB::transaction(function () use ($ada, $nama, $username, $password) {
            if ($ada) {
                $ada->forceFill([
                    'role'           => User::ROLE_ADMIN_UTAMA,
                    'cabang_id'      => null,
                    'aktif'          => true,
                    'password'       => $password,
                    'remember_token' => null,
                ])->save();

                return;
            }

            User::create([
                'nama'      => $nama,
                'username'  => $username,
                'password'  => $password,
                'role'      => User::ROLE_ADMIN_UTAMA,
                'cabang_id' => null,
                'aktif'     => true,
            ]);
        });

        return ['username' => $username, 'dipulihkan' => $ada !== null, 'password_dibuat' => $passwordDibuat];
    }
}
