<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\IsianAman;
use App\Models\User;
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

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => User::rapikanUsername($this->teks('username'))]);
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'username.max' => 'Username atau password salah.',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /** @throws ValidationException */
    public function authenticate(): void
    {
        $this->pastikanTidakDikunci();

        $kredensial = $this->only('username', 'password');

        // validate() hanya mengecek username + password, belum login,
        // supaya status aktif bisa diperiksa lebih dulu.
        if (! Auth::validate($kredensial)) {
            RateLimiter::hit($this->kunciThrottle(), self::LAMA_KUNCI);

            // Pesan sengaja umum: tidak membocorkan apakah username terdaftar.
            throw ValidationException::withMessages([
                'login' => 'Username atau password salah.',
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

    /** Penghitung percobaan per username + IP. */
    public function kunciThrottle(): string
    {
        return Str::transliterate($this->string('username').'|'.$this->ip());
    }
}
