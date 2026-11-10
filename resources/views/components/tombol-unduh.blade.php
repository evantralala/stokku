{{--
    Tombol Unduh Excel di kepala halaman daftar. Prop: href, periode (true = buka popup pilih periode dulu).
    Di HP hanya ikon.
--}}
@props(['href', 'periode' => false])

<a {{ $attributes->class('btn tombol-excel') }} href="{{ $href }}" @if ($periode) data-popup data-popup-judul="Unduh Excel" @else download @endif aria-label="Unduh Excel">
    <x-ikon nama="masuk" />
    <span>Unduh Excel</span>
</a>
