{{--
    Foto bahan baku atau menu; tanpa foto tampil huruf awal nama dengan warna lembut yang tetap per nama.
    Prop: src (URL atau null), nama, ukuran (kecil | sedang | besar).
--}}
@props(['src' => null, 'nama' => '', 'ukuran' => 'kecil'])

@php
    $awal = collect(preg_split('/\s+/u', trim((string) $nama)))
        ->filter()
        ->take(2)
        ->map(fn (string $kata) => mb_strtoupper(mb_substr($kata, 0, 1)))
        ->implode('');
    $warna = crc32(mb_strtolower(trim((string) $nama))) % 5;
@endphp

<span {{ $attributes->class(['foto', 'foto-'.$ukuran, 'foto-kosong foto-warna-'.$warna => ! $src]) }}>
    @if ($src)
        <img src="{{ $src }}" alt="{{ $ukuran === 'besar' ? 'Foto '.$nama : '' }}" loading="lazy" decoding="async">
    @else
        <span class="foto-awal" aria-hidden="true">{{ $awal }}</span>
    @endif
</span>
