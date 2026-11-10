<?php

namespace App\Http\Middleware;

use App\Services\KontrolSistemService;
use App\Support\DaftarKontrol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hak akses per peran per aksi (Pengaturan). Menu dan aksinya dikenali dari nama route
 * (lihat DaftarKontrol::aksiRute); route yang tidak diatur selalu boleh, kecuali route Admin Utama untuk peran lain.
 */
class CekAkses
{
    public function __construct(private KontrolSistemService $kontrol) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->kontrol->bolehRequest($request)) {
            return $next($request);
        }

        abort_if($request->routeIs(DaftarKontrol::AWALAN_UTAMA.'.*'), 403, 'Anda tidak memiliki akses ke halaman ini.');

        $pesan = 'Menu ini sedang tidak tersedia untuk peran Anda. Hubungi Admin Utama bila perlu.';

        return $request->isMethod('GET')
            ? redirect()->route($user->ruteDashboard())->with('galat', $pesan)
            : back(fallback: route($user->ruteDashboard()))->with('galat', $pesan);
    }
}
