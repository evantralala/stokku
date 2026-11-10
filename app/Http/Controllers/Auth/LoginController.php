<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AdminUtamaAwalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Bila belum ada Admin Utama aktif (mis. database diisi lewat impor SQL phpMyAdmin tanpa terminal), akun dibuat dari
     * ADMIN_UTAMA_* di .env. Password harus diisi di .env (minimal 8 karakter) karena tidak bisa ditampilkan di sini.
     */
    public function create(AdminUtamaAwalService $admin): View
    {
        $belumAda = ! User::query()->where('role', User::ROLE_ADMIN_UTAMA)->where('aktif', true)->exists();
        $sandiSiap = mb_strlen((string) config('stokku.admin_utama_awal.password')) >= 8;
        $dibuat = $belumAda && $sandiSiap ? $admin->pastikanAda()['email'] ?? null : null;

        return view('auth.login', [
            'adminDibuat' => $dibuat,
            'perluSandi'  => $belumAda && ! $sandiSiap,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Ganti ID sesi setelah login untuk mencegah session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route($request->user()->ruteDashboard()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
