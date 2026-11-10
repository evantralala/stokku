<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\CatatPerubahanData;
use App\Services\FotoService;
use App\Services\KontrolSistemService;
use App\Support\DaftarRiwayat;
use App\View\Composers\LayoutComposer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Satu objek per request, supaya hitungan untuk layout tidak diulang.
        $this->app->scoped(LayoutComposer::class);
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        if (config('stokku.paksa_https')) {
            URL::forceScheme('https');
        }

        // Di lokal: error kalau ada lazy loading (N+1), atribut yang tidak ada,
        // atau isian yang tidak ada di $fillable. Di production tetap longgar.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Halaman isi (admin-utama.*, pusat.*, cabang.*) dirender sebelum layout,
        // jadi didaftarkan juga supaya $pengguna sudah tersedia di sana.
        View::composer([
            'layouts.app',
            'components.sidebar',
            'components.topbar',
            'components.navbawah',
            'admin-utama.*',
            'pusat.*',
            'cabang.*',
            'akun.*',
        ], LayoutComposer::class);

        foreach (array_keys(DaftarRiwayat::NAMA_DATA) as $model) {
            if ($model !== User::class) {
                $model::observe(CatatPerubahanData::class);
            }
        }

        $this->daftarkanRuteDataMaster();

        // migrate:fresh mengosongkan database, tetapi foto unggahan dan cache di luar database tetap tertinggal.
        // Saat tes, migrate:fresh dijalankan RefreshDatabase, jadi foto di folder kerja tidak boleh ikut terhapus.
        if (! $this->app->runningUnitTests()) {
            Event::listen(DatabaseRefreshed::class, function () {
                app(FotoService::class)->hapusSemua();
                Cache::flush();
            });
        }

        // @modul('peringatan_dini') ... @endmodul  dan  @boleh('opname.catat') ... @endboleh  (Pengaturan)
        Blade::if('modul', fn (string $modul) => app(KontrolSistemService::class)->modulNyala($modul));
        Blade::if('boleh', fn (string $menuAksi) => app(KontrolSistemService::class)->boleh(auth()->user(), $menuAksi));
    }

    /**
     * Route::dataMaster('satuan', SatuanController::class) membuat route:
     *
     *   GET    satuan                 index      .satuan
     *   GET    satuan/tambah          create     .satuan.tambah
     *   POST   satuan                 store      .satuan.simpan
     *   GET    satuan/{satuan}/ubah   edit       .satuan.ubah
     *   PUT    satuan/{satuan}        update     .satuan.perbarui
     *   PATCH  satuan/{satuan}/aktif  ubahAktif  .satuan.aktif
     *   DELETE satuan/{satuan}        destroy    .satuan.hapus
     *
     * Nama parameter = uri dalam camelCase (bahan-baku -> {bahanBaku}), sesuai nama variabel di controller.
     */
    private function daftarkanRuteDataMaster(): void
    {
        Route::macro('dataMaster', function (string $uri, string $controller): void {
            $parameter = Str::camel($uri);
            $dengan = $uri.'/{'.$parameter.'}';

            Route::controller($controller)->group(function () use ($uri, $dengan, $parameter) {
                Route::get($uri, 'index')->name($uri);
                Route::get($uri.'/tambah', 'create')->name($uri.'.tambah');
                Route::post($uri, 'store')->name($uri.'.simpan');
                Route::get($dengan.'/ubah', 'edit')->name($uri.'.ubah')->whereNumber($parameter);
                Route::put($dengan, 'update')->name($uri.'.perbarui')->whereNumber($parameter);
                Route::patch($dengan.'/aktif', 'ubahAktif')->name($uri.'.aktif')->whereNumber($parameter);
                Route::delete($dengan, 'destroy')->name($uri.'.hapus')->whereNumber($parameter);
            });
        });
    }
}
