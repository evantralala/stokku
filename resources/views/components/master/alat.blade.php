{{--
    Kartu alat di kepala daftar data master: kotak cari, saringan tambahan (slot), chip status, tombol hapus saringan,
    lalu tombol aksi halaman (slot aksi: riwayat, impor/ekspor, tambah) di kanan.
    Prop: rute (awalan nama route), filter (['cari', 'status']), placeholder.
    Status berupa chip tautan yang mempertahankan saringan lain.
--}}
@props(['rute', 'filter', 'placeholder' => 'Cari'])

@php
    $pilihanStatus = ['aktif' => 'Aktif', 'nonaktif' => 'Tidak Aktif', 'semua' => 'Semua'];
    $tautanStatus = fn (string $status) => route($rute, array_filter(request()->except(['status', 'page']) + ['status' => $status], fn ($v) => $v !== null && $v !== ''));
@endphp

<div class="alat alat-master">
    <form class="alat-master-form" method="GET" action="{{ route($rute) }}" role="search">
        <div class="kotak-cari">
            <x-ikon nama="cari" width="18" height="18" />
            <label class="tersembunyi" for="cari">{{ $placeholder }}</label>
            <input class="kotak-cari-isian" id="cari" type="search" name="cari" value="{{ $filter['cari'] }}"
                   placeholder="{{ $placeholder }}" enterkeyhint="search" autocomplete="off" data-cari-utama>
        </div>

        {{ $slot }}

        <input type="hidden" name="status" value="{{ $filter['status'] }}">
    </form>

    <nav class="chip-saring chip-geser" aria-label="Saring status">
        @foreach ($pilihanStatus as $nilai => $teks)
            <a class="chip" href="{{ $tautanStatus($nilai) }}"{!! $filter['status'] === $nilai ? ' aria-current="true"' : '' !!}>{{ $teks }}</a>
        @endforeach
    </nav>

    <a class="btn tombol-reset" href="{{ route($rute) }}" aria-label="Hapus saringan" title="Hapus saringan">
        <x-ikon nama="ulang" width="18" height="18" />
    </a>

    @isset($aksi)
        <div class="alat-master-aksi">{{ $aksi }}</div>
    @endisset
</div>
