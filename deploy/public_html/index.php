<?php

/*
 * Pengganti public/index.php untuk hosting (hPanel Hostinger) yang document root-nya public_html dan tidak bisa diubah.
 * Isi folder public/ disalin ke public_html/, lalu file ini menimpa public_html/index.php.
 * Folder aplikasi diletakkan di luar public_html; sesuaikan $aplikasi bila namanya berbeda.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$aplikasi = __DIR__.'/../stokku';

if (file_exists($maintenance = $aplikasi.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $aplikasi.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $aplikasi.'/bootstrap/app.php';

// Aset hasil npm run build (public_html/build) dibaca dari folder ini, bukan dari stokku/public.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
