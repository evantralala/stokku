{{--
    Halaman galat (403, 404, 419, 429, 500, 503) bergaya sama dengan halaman login.
    Section: kode, judul, pesan.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0a1020">
<meta name="color-scheme" content="dark">
<title>@yield('judul') — {{ \App\Support\Identitas::aplikasi() }}</title>
<link rel="icon" type="image/png" href="{{ \App\Support\Identitas::logo('logo-kecil.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@vite(['resources/css/login.css'])
</head>
<body>
<main class="galat-halaman">
  <div class="galat-kilau" aria-hidden="true"></div>
  <div class="galat-isi">
    <img class="galat-logo {{ \App\Support\Identitas::kelasLogo() }}" src="{{ \App\Support\Identitas::logo() }}" alt="{{ \App\Support\Identitas::aplikasi() }}" width="56" height="73">
    <p class="galat-kode">@yield('kode')</p>
    <h1>@yield('judul')</h1>
    <p class="galat-pesan">@yield('pesan')</p>
    <a class="tombol-masuk galat-tombol" href="{{ url('/') }}">
      Kembali ke beranda
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
    </a>
  </div>
</main>
</body>
</html>
