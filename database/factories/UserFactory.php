<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data pengguna untuk pengujian.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Hash password disimpan sekali supaya pengujian tidak lambat. */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'nama'           => fake('id_ID')->name(),
            'email'          => fake()->unique()->safeEmail(),
            'password'       => static::$password ??= Hash::make('password'),
            'role'           => User::ROLE_ADMIN_CABANG,
            'cabang_id'      => null,
            'aktif'          => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function adminUtama(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN_UTAMA, 'cabang_id' => null]);
    }

    public function adminPusat(int $cabangId): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN_PUSAT, 'cabang_id' => $cabangId]);
    }

    public function adminCabang(int $cabangId): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN_CABANG, 'cabang_id' => $cabangId]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn () => ['aktif' => false]);
    }
}
