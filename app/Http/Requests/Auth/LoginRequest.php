<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\IsianAman;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validasi dan proses login. Semua error login dikirim ke key 'login'
 * supaya tampil di satu kotak di atas form.
 */
class LoginRequest extends FormRequest
{
    use IsianAman;

    private const MAKS_PERCOBAAN = 5;

    /** Lama kunci (detik) setelah batas percobaan gagal tercapai. */
    private const LAMA_KUNCI = 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email belum benar. Contoh: nama@email.com',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /** @throws ValidationException */
    public function authenticate(): void
    {
        $this->pastikanTidakDikunci();

        $kredensial = $this->only('email', 'password');

        // validate() hanya mengecek email + password, belum login,
        // supaya status aktif bisa diperiksa lebih dulu.
        if (! Auth::validate($kredensial)) {
            RateLimiter::hit($this->kunciThrottle(), self::LAMA_KUNCI);

            // Pesan sengaja umum: tidak membocorkan apakah email terdaftar.
            throw ValidationException::withMessages([
                'login' => 'Email atau password salah.',
            ]);
        }

        $user = Auth::getLastAttempted();

        if (! $user->aktif) {
            throw ValidationException::withMessages([
                'login' => 'Akun Anda dinonaktifkan. Hubungi Admin Utama.',
            ]);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->kunciThrottle());
    }

    /** @throws ValidationException */
    public function pastikanTidakDikunci(): void
    {
        if (! RateLimiter::tooManyAttempts($this->kunciThrottle(), self::MAKS_PERCOBAAN)) {
            return;
        }

        event(new Lockout($this));

        $detik = RateLimiter::availableIn($this->kunciThrottle());

        throw ValidationException::withMessages([
            'login' => "Terlalu banyak percobaan gagal. Coba lagi dalam {$detik} detik.",
        ]);
    }

    /** Penghitung percobaan per email + IP. */
    public function kunciThrottle(): string
    {
        return Str::transliterate(Str::lower($this->teks('email')).'|'.$this->ip());
    }
}
