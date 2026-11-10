{{--
    Banner sapaan di atas dashboard. Variabel: $pengguna (LayoutComposer), $ringkas (satu kalimat keadaan hari ini),
    $ruteCari (nama route halaman stok yang menerima ?cari=, atau null).
--}}
<section class="sapaan">
    <div class="sapaan-teks">
        <div class="sapaan-waktu num">{{ now()->translatedFormat('l, j F Y · H.i') }}</div>
        <h2 class="sapaan-judul">Selamat bertugas, {{ \Illuminate\Support\Str::of($pengguna->nama)->before(' ') }}</h2>
        <p class="sapaan-ringkas">{{ $ringkas }}</p>
    </div>

    @if ($ruteCari)
        <form class="sapaan-cari" role="search" method="GET" action="{{ route($ruteCari) }}">
            <label class="sapaan-cari-label" for="cari-dasbor">Pencarian Bahan</label>
            <div class="sapaan-cari-kotak">
                <x-ikon nama="cari" width="18" height="18" />
                <input id="cari-dasbor" type="search" name="cari" placeholder="Cari nama bahan…" autocomplete="off"
                       enterkeyhint="search" data-cari-global>
            </div>
        </form>
    @endif
</section>
