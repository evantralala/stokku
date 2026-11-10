<?php

use App\Http\Controllers\VersiDataController;
use App\Http\Middleware\BacaNotifikasiHalaman;
use App\Http\Middleware\CekAkses;
use App\Http\Middleware\CekModul;
use App\Http\Middleware\CekRole;
use App\Http\Middleware\NaikkanVersiData;
use App\Http\Middleware\PaksaHttps;
use App\Http\Middleware\PertahankanIsianPopup;
use App\Http\Middleware\RapikanKueri;
use App\Http\Middleware\TanpaCacheBrowser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Ditanya setiap beberapa detik oleh halaman yang terbuka: tanpa sesi, cookie, dan CSRF supaya ringan.
            Route::get('/segar/versi', VersiDataController::class)->name('segar.versi');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CekRole::class,
            'akses' => CekAkses::class,
            'modul' => CekModul::class,
        ]);

        // Hosting di belakang proxy SSL: hanya penanda https (X-Forwarded-Proto) yang dipercaya.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);
        $middleware->prepend(PaksaHttps::class);

        $middleware->web(prepend: [RapikanKueri::class]);
        $middleware->web(append: [TanpaCacheBrowser::class, PertahankanIsianPopup::class, BacaNotifikasiHalaman::class, NaikkanVersiData::class]);

        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));

        $middleware->redirectUsersTo(
            fn (Request $request) => route($request->user()->ruteDashboard())
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->expectsJson());
    })->create();