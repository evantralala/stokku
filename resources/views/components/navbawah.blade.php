{{-- Navigasi bawah untuk HP: menu utama per role + tombol "Menu" untuk membuka laci. --}}
<nav class="navbawah" aria-label="Menu cepat">
    @foreach (\App\View\MenuSidebar::utama($pengguna) as $item)
        @php $aktif = \App\View\MenuSidebar::sedangDibuka($item); @endphp
        <a class="navbawah-item {{ $aktif ? 'on' : '' }}" id="navbawah-{{ $item['route'] }}" data-segar href="{{ route($item['route']) }}"
           @if ($aktif) aria-current="page" @endif>
            <x-ikon :nama="$item['ikon']" class="ico" />
            <span>{{ $item['pendek'] ?? $item['label'] }}</span>
            @if ($item['jumlah'] && $jumlah[$item['jumlah']] > 0)
                <span class="pill">{{ $jumlah[$item['jumlah']] }}</span>
            @endif
        </a>
    @endforeach

    <button type="button" class="navbawah-item" data-buka-menu aria-controls="sidebar" aria-expanded="false">
        <x-ikon nama="menu" class="ico" />
        <span>Menu</span>
    </button>
</nav>