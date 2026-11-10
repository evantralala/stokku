{{--
    Kerangka halaman setelah login. Section: judul (wajib), content (wajib), sub, crumb, aksi, lampu,
    segar (isi apa saja: konten halaman diperbarui otomatis, lihat resources/js/segar.js; tidak untuk halaman hasil kirim form). Stack: scripts.
    Pesan sukses/galat tampil sebagai toast.
    HP (< 640px): kepala gelap + navigasi bawah. Tablet (640–1023px): rel ikon di kiri + laci menu.
    Laptop (>= 1024px): sidebar lebar yang bisa diciutkan; pilihan diingat browser per pengguna (data-kunci-sidebar).
--}}
<!DOCTYPE html>
<html lang="id" data-kunci-sidebar="stokku:sidebar-ciut:{{ auth()->id() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a1020">
    <meta name="color-scheme" content="dark">
    <link rel="icon" type="image/png" href="{{ \App\Support\Identitas::logo('logo-kecil.png') }}">
    @include('layouts._pwa')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="pengguna" content="{{ auth()->id() }}">
    @if (request()->isMethod('GET'))
        <meta name="jeda-segar" content="{{ config('stokku.jeda_segar_detik') }}">
        <meta name="versi-data" content="{{ \App\Support\VersiData::sekarang() }}">
        <meta name="url-versi" content="{{ route('segar.versi') }}">
    @endif
    @if ($errors->any() && is_string(old('_popup')))
        <meta name="popup-ulang" content="{{ old('_popup') }}">
    @elseif (is_string(session('popup_buka')))
        <meta name="popup-buka" content="{{ session('popup_buka') }}">
    @endif
    <title>@yield('judul') — {{ \App\Support\Identitas::aplikasi() }}</title>

    <script>
        try {
            if (localStorage.getItem(document.documentElement.dataset.kunciSidebar) === '1') {
                document.documentElement.classList.add('sidebar-ciut');
            }
        } catch (e) {}
    </script>
    @include('layouts._transisi')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<a class="lompat" href="#isi">Langsung ke isi halaman</a>

<div class="app">

    @include('components.sidebar')

    <div class="tirai" data-tutup-menu hidden></div>

    <div class="main">

        @include('components.topbar')

        <main class="content" id="isi" tabindex="-1">
            @hasSection('segar')
                <div class="isi-segar" id="isi-segar" data-segar>
                    @yield('content')
                </div>
            @else
                @yield('content')
            @endif
        </main>

    </div>

</div>

@include('components.navbawah')

<x-toast />
<x-popup />
<x-dialog-konfirmasi />

@stack('scripts')
</body>
</html>
