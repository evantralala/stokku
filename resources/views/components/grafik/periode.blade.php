{{--
    Pilihan periode grafik (Bulan Ini, Bulan Lalu, Tahun Ini, Tahun Lalu) sebagai chip tautan; pilihan lain di alamat tetap.
    Prop: nama (nama isian di alamat), terpilih (kunci periode), jangkar (id grafik supaya halaman kembali ke grafiknya).
--}}
@props(['nama', 'terpilih', 'jangkar'])

<nav class="chip-saring chip-geser grafik-periode" aria-label="Periode grafik">
    @foreach (\App\Services\GrafikDasborService::PERIODE as $kunci => $label)
        <a class="chip" href="{{ request()->fullUrlWithQuery([$nama => $kunci]) }}#{{ $jangkar }}"{!! $terpilih === $kunci ? ' aria-current="true"' : '' !!}>{{ $label }}</a>
    @endforeach
</nav>
