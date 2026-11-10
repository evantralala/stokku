@echo off
rem Membuat paket rilis untuk Hostinger (hPanel) dari repo lokal Windows.
rem Hasil: deploy\stokku-rilis.zip (tanpa node_modules, tests, .env, log, dan cache).
rem Jalankan dari folder proyek: deploy\buat-paket.bat

setlocal
cd /d "%~dp0.."

echo [1/4] Membuat aset (npm run build)...
call npm run build || goto gagal

echo [2/4] Memasang paket PHP produksi (composer install --no-dev)...
call composer install --no-dev --optimize-autoloader --no-interaction || goto gagal

echo [3/4] Membungkus file...
if exist deploy\stokku-rilis.zip del deploy\stokku-rilis.zip
tar -a -c -f deploy\stokku-rilis.zip ^
  --exclude=node_modules --exclude=tests --exclude=.git --exclude=.env ^
  --exclude=storage/logs/*.log --exclude=storage/framework/cache/data/* ^
  --exclude=storage/framework/sessions/* --exclude=storage/framework/views/*.php ^
  --exclude=storage/app/private/cadangan/* --exclude=deploy/stokku-rilis.zip ^
  --exclude=public/storage --exclude=.phpunit.result.cache ^
  app bootstrap config database lang public resources routes storage vendor deploy ^
  artisan composer.json composer.lock .env.production.example || goto gagal

echo [4/4] Mengembalikan paket pengembangan (composer install)...
call composer install --no-interaction || goto gagal

echo.
echo Selesai: deploy\stokku-rilis.zip
goto :eof

:gagal
echo.
echo Gagal. Periksa pesan di atas, lalu jalankan lagi.
exit /b 1
