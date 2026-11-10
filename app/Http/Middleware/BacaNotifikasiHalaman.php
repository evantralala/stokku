<?php

namespace App\Http\Middleware;

use App\Services\NotifikasiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notifikasi milik pengguna yang tautannya sama dengan halaman yang sedang dibuka dianggap sudah dibaca,
 * baik halaman itu dibuka dari lonceng maupun dari menu biasa.
 */
class BacaNotifikasiHalaman
{
    public function __construct(private NotifikasiService $notifikasi) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->user();

        if ($pengguna !== null && $request->isMethod('GET')) {
            $jalur = '/'.ltrim($request->path(), '/');
            $kueri = (string) $request->server('QUERY_STRING');

            $this->notifikasi->bacaHalaman($pengguna, $kueri === '' ? [$jalur] : [$jalur, $jalur.'?'.$kueri]);
        }

        return $next($request);
    }
}
