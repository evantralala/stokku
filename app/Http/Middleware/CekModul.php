<?php

namespace App\Http\Middleware;

use App\Services\KontrolSistemService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Menolak halaman milik modul yang sedang dimatikan, contoh: ->middleware('modul:peringatan_dini'). */
class CekModul
{
    public function __construct(private KontrolSistemService $kontrol) {}

    public function handle(Request $request, Closure $next, string $modul): Response
    {
        if ($this->kontrol->modulNyala($modul)) {
            return $next($request);
        }

        $pesan = $this->kontrol->teksModulMati($modul);
        $user = $request->user();

        if ($user?->isAdminUtama()) {
            $pesan .= ' Nyalakan lagi di Pengaturan bila perlu.';
        }

        return redirect()
            ->route($user?->ruteDashboard() ?? 'login')
            ->with('galat', $pesan);
    }
}
