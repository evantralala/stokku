{{--
    Sidebar (laptop) / laci menu (HP & tablet). Butuh $pengguna dan $jumlah dari LayoutComposer.
    Menu dikelompokkan per grup (App\View\MenuSidebar); menu aktif bergradasi merah logo.
    Di laptop bisa diciutkan menjadi ikon lewat [data-ciutkan-sidebar] (resources/js/sidebar-ciut.js).
    Kaki sidebar: tombol ciutkan dan keluar. Nama pengguna tampil di kartu pengguna bilah atas.
--}}
@php $menu = \App\View\MenuSidebar::untuk($pengguna); @endphp

<aside class="sidebar" id="sidebar" aria-label="Menu utama">
    <div class="brand">
        <img class="brand-tanda {{ \App\Support\Identitas::kelasLogo() }}" src="{{ \App\Support\Identitas::logo('logo-kecil.png') }}" alt="" width="38" height="50">
        <div class="brand-teks">
            <div class="brand-word">{{ \App\Support\Identitas::aplikasi() }}</div>
            <div class="brand-sub">{{ $pengguna->label_role }}</div>
        </div>
        <button type="button" class="tutup-menu" data-tutup-menu aria-label="Tutup menu">
            <x-ikon nama="silang" width="22" height="22" />
        </button>
    </div>

    <nav>
        @foreach ($menu as $grup => $daftar)
            <div class="nav-group">{{ $grup }}</div>

            @foreach ($daftar as $item)
                @php $aktif = \App\View\MenuSidebar::sedangDibuka($item); @endphp
                <a class="nav-item {{ $aktif ? 'on' : '' }}" id="nav-{{ $item['route'] }}" data-segar href="{{ route($item['route']) }}"
                   @if ($aktif) aria-current="page" @endif>
                    <x-ikon :nama="$item['ikon']" class="ico" />
                    <span class="nav-teks">{{ $item['label'] }}</span>
                    @if ($item['jumlah'] && $jumlah[$item['jumlah']] > 0)
                        <span class="pill">{{ $jumlah[$item['jumlah']] }}</span>
                    @endif
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="sidebar-kaki">
        <button type="button" class="nav-item tombol-ciut" data-ciutkan-sidebar aria-pressed="false" hidden>
            <x-ikon nama="ciutkan" class="ico ikon-ciutkan" />
            <x-ikon nama="lebarkan" class="ico ikon-lebarkan" />
            <span class="nav-teks">Ciutkan Menu</span>
        </button>

        <form class="keluar" method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="nav-item" type="submit">
                <x-ikon nama="pintu" class="ico" />
                <span class="nav-teks">Keluar</span>
            </button>
        </form>
    </div>
</aside>
