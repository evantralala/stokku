<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Alihkan permintaan http:// ke https:// bila PAKSA_HTTPS=true (lihat config/stokku.php). */
class PaksaHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('stokku.paksa_https') && ! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
