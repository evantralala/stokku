{{--
    Dua bagian:
    1. Bilah atas putih (lekat): judul halaman, section 'status' (badge status dokumen) + sub di kiri;
       kotak cari bahan, tombol "?" (popup bantuan topik halaman ini, bila ada), lonceng notifikasi,
       dan kartu pengguna (nama, lokasi/peran; menuju Akun) di kanan.
    2. Baris halaman (.topbar) di bawahnya: crumb (tombol kembali) di kiri, section 'aksi' (tombol milik halaman ini) di kanan.
       Bila ada section 'lampu', lampu dan aksi pindah ke baris bawah (.topbar-bawah).
       Section 'alat' (cari & saring halaman, mis. di Stok) juga tampil di baris bawah. Tanpa crumb, aksi, lampu, dan alat
       baris ini tidak ditulis.
    Kotak cari menuju halaman Stok peran itu, tidak tampil di halaman Stok, Bantuan, dan dashboard (sudah punya pencarian sendiri),
    dan disembunyikan di HP. Tombol "/" memfokuskan kotak cari.
--}}
@php
    $belumDibaca = auth()->check()
        ? \App\Models\Notifikasi::query()->milik(auth()->id())->belumDibaca()->count()
        : 0;

    $adaLampu = trim($__env->yieldContent('lampu')) !== '';
    $adaAksi = trim($__env->yieldContent('aksi')) !== '';
    $adaAlat = trim($__env->yieldContent('alat')) !== '';
    $adaStatus = trim($__env->yieldContent('status')) !== '';
    $adaCrumb = trim($__env->yieldContent('crumb')) !== '';
    $konteks = $pengguna?->cabang?->nama ?? $pengguna?->label_role;
    $ruteCari = $pengguna ? \App\View\MenuSidebar::ruteCari($pengguna) : null;
    $ruteCari = $ruteCari && ! request()->routeIs($ruteCari, 'bantuan', '*.dashboard') ? $ruteCari : null;
    $bantuan = $pengguna && ! request()->routeIs('bantuan')
        ? app(\App\Services\BantuanService::class)->topikUntukRute($pengguna, request()->route()?->getName())
        : null;
@endphp

<div class="bilah-atas">
    <div class="bilah-judul">
        <h1>@yield('judul')</h1>

        @if ($adaStatus || trim($__env->yieldContent('sub')) !== '')
            <div class="tgl">
                @if ($adaStatus)
                    <span class="kepala-status">@yield('status')</span>
                @endif
                @yield('sub')
            </div>
        @endif
    </div>

    @if ($ruteCari)
        <form class="cari-global" role="search" method="GET" action="{{ route($ruteCari) }}">
            <x-ikon nama="cari" class="cari-global-ikon" width="18" height="18" />
            <label class="tersembunyi" for="cari-global">Cari Bahan</label>
            <input class="cari-global-isian" id="cari-global" type="search" name="cari"
                   placeholder="Cari bahan" autocomplete="off" enterkeyhint="search" data-cari-global>
            <kbd class="cari-global-pintas" aria-hidden="true">/</kbd>
        </form>
    @endif

    @if ($bantuan)
        <a class="tombol-bantuan" href="{{ route('bantuan', ['topik' => $bantuan['kunci']]) }}#bantuan-{{ $bantuan['kunci'] }}"
           data-popup data-popup-judul="{{ $bantuan['judul'] }}" aria-label="Bantuan: {{ $bantuan['judul'] }}">
            <x-ikon nama="bantuan" width="20" height="20" />
        </a>
    @endif

    @auth
        <a class="tombol-lonceng" id="tombol-lonceng" data-segar href="{{ route('notifikasi') }}"
           aria-label="Notifikasi{{ $belumDibaca ? ', '.$belumDibaca.' belum dibaca' : '' }}">
            <x-ikon nama="lonceng" width="20" height="20" />
            @if ($belumDibaca > 0)
                <span class="lonceng-angka" aria-hidden="true">{{ $belumDibaca > 99 ? '99+' : $belumDibaca }}</span>
            @endif
        </a>
    @endauth

    @if ($pengguna)
        <a class="kartu-pengguna" href="{{ route('akun') }}" aria-label="Akun: {{ $pengguna->nama }}, {{ $konteks }}">
            <x-ava :pengguna="$pengguna" />
            <span class="kartu-pengguna-teks">
                <span class="kartu-pengguna-nama">{{ $pengguna->nama }}</span>
                <span class="kartu-pengguna-peran">{{ $konteks }}</span>
            </span>
        </a>
    @endif
</div>

@if ($adaCrumb || $adaAksi || $adaLampu || $adaAlat)
    <header @class(['topbar', 'ada-lampu' => $adaLampu, 'ada-alat' => $adaAlat])>
        @if ($adaCrumb)
            <div class="crumb">@yield('crumb')</div>
        @endif

        @if ($adaAksi && ! $adaLampu)
            <div class="top-act">
                @yield('aksi')
            </div>
        @endif

        @if ($adaLampu || $adaAlat)
            <div class="topbar-bawah">
                @if ($adaLampu)
                    <div class="topbar-lampu" id="topbar-lampu" data-segar>
                        @yield('lampu')
                    </div>
                @endif

                @if ($adaAlat)
                    <div class="topbar-alat">
                        @yield('alat')
                    </div>
                @endif

                @if ($adaAksi && $adaLampu)
                    <div class="top-act">
                        @yield('aksi')
                    </div>
                @endif
            </div>
        @endif
    </header>
@endif
