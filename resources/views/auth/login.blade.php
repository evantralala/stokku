{{--
    Halaman login, berdiri sendiri (tanpa layouts.app). Error ditampilkan dari key email, password, dan login.
    Laptop (>= 900px): kiri ilustrasi gudang bergerak + pembatas bergelombang, kanan form.
    HP & tablet: ilustrasi menjadi kepala ringkas di atas, form di bawahnya.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0a1020">
<meta name="color-scheme" content="dark">
<title>Masuk — {{ \App\Support\Identitas::aplikasi() }}</title>
<link rel="icon" type="image/png" href="{{ \App\Support\Identitas::logo('logo-kecil.png') }}">
@include('layouts._pwa')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
@vite(['resources/css/login.css', 'resources/js/app.js'])
</head>
<body>

<div class="layar">

  {{-- ============ KIRI: merek + ilustrasi bergerak ============ --}}
  <section class="kiri">
    <div class="kilau" aria-hidden="true"></div>

    <div class="merek">
      <img class="merek-logo {{ \App\Support\Identitas::kelasLogo() }}" src="{{ \App\Support\Identitas::logo() }}" alt="" width="44" height="57">
      <div>
        <div class="brand-word">{{ \App\Support\Identitas::singkat() }}</div>
        <div class="brand-sub">Manajemen Stok</div>
      </div>
    </div>

    <div class="adegan" aria-hidden="true">
      <svg class="ilus" viewBox="0 0 520 400" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="il-merah" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#f0453e"/>
            <stop offset="1" stop-color="#b5160f"/>
          </linearGradient>
          <linearGradient id="il-panel" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#1c2846"/>
            <stop offset="1" stop-color="#111a30"/>
          </linearGradient>
          <linearGradient id="il-kardus" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#e3a35a"/>
            <stop offset="1" stop-color="#c47f37"/>
          </linearGradient>
        </defs>

        {{-- cincin putus-putus berputar di belakang --}}
        <circle class="il-cincin" cx="250" cy="190" r="150" stroke="rgb(255 255 255 / 0.12)" stroke-width="2" stroke-dasharray="6 10"/>
        <circle cx="250" cy="190" r="108" fill="rgb(221 42 35 / 0.10)"/>

        {{-- lantai --}}
        <ellipse cx="250" cy="352" rx="210" ry="18" fill="rgb(0 0 0 / 0.35)"/>

        {{-- rak gudang --}}
        <g>
          <rect x="70" y="96" width="10" height="256" rx="3" fill="#2d3c60"/>
          <rect x="262" y="96" width="10" height="256" rx="3" fill="#2d3c60"/>
          <rect x="70" y="170" width="202" height="9" rx="3" fill="#3a4b74"/>
          <rect x="70" y="252" width="202" height="9" rx="3" fill="#3a4b74"/>
          <rect x="70" y="334" width="202" height="9" rx="3" fill="#3a4b74"/>

          {{-- baris atas: kardus jatuh mengisi rak --}}
          <g class="il-jatuh">
            <rect x="92" y="124" width="54" height="46" rx="5" fill="url(#il-kardus)"/>
            <rect x="114" y="124" width="10" height="46" fill="rgb(255 255 255 / 0.25)"/>
          </g>
          <g class="il-apung il-tunda-1">
            <rect x="158" y="134" width="44" height="36" rx="5" fill="url(#il-merah)"/>
            <path d="M172 146h16M172 154h10" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
          </g>
          <rect x="212" y="140" width="40" height="30" rx="5" fill="#3b82f6"/>

          {{-- baris tengah: karung beras dan kardus --}}
          <path d="M92 252c-4-26 4-40 14-44h28c10 4 18 18 14 44z" fill="#e9e1d4"/>
          <path d="M104 208c6-6 26-6 32 0" stroke="#b9ad99" stroke-width="3" stroke-linecap="round"/>
          <rect x="110" y="224" width="22" height="14" rx="3" fill="url(#il-merah)"/>
          <g class="il-apung il-tunda-2">
            <rect x="162" y="210" width="48" height="42" rx="5" fill="url(#il-kardus)"/>
            <rect x="181" y="210" width="10" height="42" fill="rgb(255 255 255 / 0.25)"/>
          </g>
          <rect x="218" y="222" width="36" height="30" rx="5" fill="#22c55e"/>

          {{-- baris bawah --}}
          <rect x="90" y="290" width="62" height="44" rx="5" fill="url(#il-kardus)"/>
          <rect x="116" y="290" width="10" height="44" fill="rgb(255 255 255 / 0.25)"/>
          <rect x="160" y="300" width="42" height="34" rx="5" fill="#a855f7"/>
          <rect x="210" y="296" width="44" height="38" rx="5" fill="url(#il-kardus)"/>
        </g>

        {{-- panel tablet dengan grafik yang tumbuh --}}
        <g class="il-apung il-tunda-3">
          <rect x="300" y="118" width="170" height="150" rx="16" fill="url(#il-panel)" stroke="rgb(255 255 255 / 0.12)"/>
          <rect x="318" y="136" width="64" height="8" rx="4" fill="rgb(255 255 255 / 0.55)"/>
          <rect x="318" y="150" width="40" height="6" rx="3" fill="rgb(255 255 255 / 0.22)"/>
          <circle cx="446" cy="144" r="11" fill="#18995a"/>
          <path class="il-centang" d="m441 144 4 4 7-8" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M318 250h134" stroke="rgb(255 255 255 / 0.12)" stroke-width="2"/>
          <rect class="il-batang il-b1" x="324" y="206" width="18" height="44" rx="4" fill="#3b82f6"/>
          <rect class="il-batang il-b2" x="352" y="186" width="18" height="64" rx="4" fill="#a855f7"/>
          <rect class="il-batang il-b3" x="380" y="196" width="18" height="54" rx="4" fill="#22c55e"/>
          <rect class="il-batang il-b4" x="408" y="172" width="18" height="78" rx="4" fill="url(#il-merah)"/>
        </g>

        {{-- label melayang --}}
        <g class="il-apung il-tunda-2">
          <rect x="330" y="62" width="118" height="34" rx="17" fill="#121b2f" stroke="rgb(52 199 123 / 0.5)"/>
          <circle class="il-denyut" cx="350" cy="79" r="5" fill="#34c77b"/>
          <text x="362" y="84" fill="#e8edf6" font-family="Plus Jakarta Sans, sans-serif" font-size="13" font-weight="700">Stok aman</text>
        </g>
        <g class="il-apung il-tunda-1">
          <rect x="20" y="50" width="96" height="34" rx="17" fill="url(#il-merah)"/>
          <text x="40" y="72" fill="#fff" font-family="Plus Jakarta Sans, sans-serif" font-size="13" font-weight="800">+20 kg</text>
        </g>

        {{-- truk pengiriman berjalan di bawah --}}
        <g class="il-truk">
          <rect x="300" y="300" width="84" height="44" rx="6" fill="url(#il-merah)"/>
          <path d="M384 312h22l16 16v16h-38z" fill="#e8edf6"/>
          <path d="M390 318h14l10 10h-24z" fill="#5b9bff"/>
          <rect x="312" y="314" width="34" height="6" rx="3" fill="rgb(255 255 255 / 0.7)"/>
          <g class="il-roda"><circle cx="322" cy="346" r="9" fill="#0b1222"/><circle cx="322" cy="346" r="3.5" fill="#95a1b9"/><path d="M322 338v16" stroke="#95a1b9" stroke-width="1.5"/></g>
          <g class="il-roda"><circle cx="402" cy="346" r="9" fill="#0b1222"/><circle cx="402" cy="346" r="3.5" fill="#95a1b9"/><path d="M402 338v16" stroke="#95a1b9" stroke-width="1.5"/></g>
        </g>
      </svg>
    </div>

    <div class="kiri-isi">
      <h2>Stok gudang dan semua cabang, dalam satu catatan.</h2>

      <ul class="poin">
        <li class="poin-item">
          <span class="poin-ikon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          </span>
          <span class="poin-judul">Stok berubah setelah disetujui Admin Utama</span>
        </li>
        <li class="poin-item">
          <span class="poin-ikon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
          </span>
          <span class="poin-judul">Kiriman dihitung ulang di cabang</span>
        </li>
        <li class="poin-item">
          <span class="poin-ikon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/></svg>
          </span>
          <span class="poin-judul">Pemakaian bahan dihitung dari resep</span>
        </li>
      </ul>
    </div>

    <div class="kiri-kaki">&copy; {{ date('Y') }} {{ \App\Support\Identitas::usaha() }}@if (\App\Support\Identitas::kota() !== '') &middot; {{ \App\Support\Identitas::kota() }}@endif</div>

    <svg class="gelombang gelombang-tegak" viewBox="0 0 60 800" preserveAspectRatio="none" aria-hidden="true">
      <path d="M60 0H30C6 25 6 75 30 100 S54 175 30 200 S6 275 30 300 S54 375 30 400 S6 475 30 500 S54 575 30 600 S6 675 30 700 S54 775 30 800H60Z"/>
    </svg>
    <svg class="gelombang gelombang-datar" viewBox="0 0 800 40" preserveAspectRatio="none" aria-hidden="true">
      <path d="M0 40V20C25 4 75 4 100 20 S175 36 200 20 S275 4 300 20 S375 36 400 20 S475 4 500 20 S575 36 600 20 S675 4 700 20 S775 36 800 20V40Z"/>
    </svg>
  </section>

  {{-- ============ KANAN: form ============ --}}
  <section class="kanan">
    <div class="kotak">
      <p class="sapa">Selamat datang kembali</p>
      <h1>Masuk</h1>
      <p class="ket">Gunakan akun yang diberikan Admin Utama.</p>

      <div class="kartu">

        @if ($perluSandi ?? false)
          <div class="kotak-galat" role="alert">
            <span>Belum ada akun Admin Utama. Isi ADMIN_UTAMA_EMAIL dan ADMIN_UTAMA_PASSWORD (minimal 8 karakter) di file .env, lalu muat ulang halaman ini.</span>
          </div>
        @elseif ($adminDibuat ?? null)
          <div class="kotak-galat" role="status">
            <span>Akun Admin Utama dibuat: {{ $adminDibuat }}. Masuk dengan password dari file .env, lalu segera ganti di Akun Saya.</span>
          </div>
        @endif

        {{-- Gagal masuk (email/sandi salah, akun nonaktif) --}}
        @error('login')
          <div class="kotak-galat" role="alert">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
            <span>{{ $message }}</span>
          </div>
        @enderror

        <form method="POST" action="{{ route('login.store') }}" novalidate>
          @csrf

          <div class="baris">
            <label for="email">Email</label>
            <div class="isian-ikon">
              <svg class="isian-tanda" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
              <input class="isian @error('email') galat @enderror"
                     id="email" name="email" type="email"
                     value="{{ old('email') }}"
                     placeholder="nama@email.com" autocomplete="username" autofocus
                     inputmode="email" autocapitalize="none" spellcheck="false">
            </div>
            @error('email')
              <div class="pesan-galat">{{ $message }}</div>
            @enderror
          </div>

          <div class="baris">
            <label for="sandi">Kata Sandi</label>
            <div class="isian-ikon">
              <svg class="isian-tanda" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
              <input class="isian @error('password') galat @enderror"
                     id="sandi" name="password" type="password"
                     placeholder="Masukkan kata sandi" autocomplete="current-password">
              <button type="button" class="lihat-sandi" data-lihat-sandi="sandi" aria-pressed="false" aria-label="Tampilkan kata sandi" hidden>
                <svg class="ikon-mata" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="ikon-mata-tutup" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A9.9 9.9 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3 3.6M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.6 9.6 0 0 0 4.5-1.1"/></svg>
              </button>
            </div>
            @error('password')
              <div class="pesan-galat">{{ $message }}</div>
            @enderror
          </div>

          <div class="bantu-baris">
            <label class="ingat">
              <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
              Ingat Saya
            </label>
            <a class="lupa" href="{{ route('password.request') }}">Lupa sandi?</a>
          </div>

          <button type="submit" class="tombol-masuk">
            Masuk
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
          </button>
        </form>

      </div>

      <div class="kaki">Belum punya akun? Minta ke Admin Utama.</div>
    </div>
  </section>

</div>

</body>
</html>
