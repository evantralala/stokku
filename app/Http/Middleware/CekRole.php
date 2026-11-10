<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi route untuk role tertentu, contoh: ->middleware('role:admin_utama')
 * atau ->middleware('role:admin_utama,admin_pusat').
 *
 * Akun yang dinonaktifkan saat masih login langsung dikeluarkan.
 */
class CekRole
{
    public function handle(Request $request, Closure $next, string ...$role): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->aktif) {
            return $this->keluarkan($request, 'Akun Anda dinonaktifkan. Hubungi Admin Utama.');
        }

        if (! in_array($user->role, $role, true)) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }

    private function keluarkan(Request $request, string $pesan): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['login' => $pesan]);
    }
}
