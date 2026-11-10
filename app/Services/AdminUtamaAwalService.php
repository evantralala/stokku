<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menjamin selalu ada minimal satu akun Admin Utama aktif (dipakai seeder).
 *
 * Data akun dari config stokku.admin_utama_awal (ADMIN_UTAMA_NAMA, ADMIN_UTAMA_EMAIL, ADMIN_UTAMA_PASSWORD).
 * Bila nama atau email kosong dipakai NAMA_BAWAAN / EMAIL_BAWAAN; bila password kosong (atau kurang dari 8 karakter)
 * dibuat password acak dan dikembalikan supaya bisa ditampilkan sekali di layar.
 * Bila email itu sudah dipakai akun lain, akun itu dijadikan Admin Utama aktif dengan password baru.
 */
class AdminUtamaAwalService
{
    public const NAMA_BAWAAN = 'Admin Utama';
    public const EMAIL_BAWAAN = 'admin@stokku.test';
    public const PANJANG_PASSWORD_ACAK = 12;
    private const PANJANG_PASSWORD_MIN = 8;

    /**
     * @return array{email: string, dipulihkan: bool, password_dibuat: ?string}|null null bila sudah ada Admin Utama aktif
     */
    public function pastikanAda(): ?array
    {
        if (User::query()->where('role', User::ROLE_ADMIN_UTAMA)->where('aktif', true)->exists()) {
            return null;
        }

        $data = config('stokku.admin_utama_awal', []);
        $nama = trim((string) ($data['nama'] ?? '')) ?: self::NAMA_BAWAAN;
        $email = Str::lower(trim((string) ($data['email'] ?? ''))) ?: self::EMAIL_BAWAAN;
        $password = (string) ($data['password'] ?? '');
        $passwordDibuat = null;

        if (mb_strlen($password) < self::PANJANG_PASSWORD_MIN) {
            $password = $passwordDibuat = Str::password(self::PANJANG_PASSWORD_ACAK, symbols: false);
        }

        $ada = User::query()->where('email', $email)->first();

        DB::transaction(function () use ($ada, $nama, $email, $password) {
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
                'email'     => $email,
                'password'  => $password,
                'role'      => User::ROLE_ADMIN_UTAMA,
                'cabang_id' => null,
                'aktif'     => true,
            ]);
        });

        return ['email' => $email, 'dipulihkan' => $ada !== null, 'password_dibuat' => $passwordDibuat];
    }
}
