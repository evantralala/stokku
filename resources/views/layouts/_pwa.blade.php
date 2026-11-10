{{-- Tag kepala supaya aplikasi bisa dipasang di HP (PWA): manifest, ikon layar utama, dan alamat service worker. --}}
<link rel="manifest" href="{{ route('manifest') }}">
<link rel="apple-touch-icon" href="{{ \App\Support\Identitas::logo('apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \App\Support\Identitas::singkat() }}">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="service-worker" content="{{ asset('sw.js') }}">
