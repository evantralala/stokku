{{--
    Indikator status stok: label Habis / Menipis / Aman dengan warna lampu, ditambah rincian kecil
    (Minus, Di bawah minimum, Hampir minimum) bila ada. Teks tetap terbaca tanpa warna.
    Prop: status (kunci PantauanStokService::TAMPILAN_STATUS), rinci (tampilkan rincian; bawaan true).
--}}
@props(['status', 'rinci' => true])

@php
    $label = \App\Services\PantauanStokService::label($status);
    $rincian = $rinci ? \App\Services\PantauanStokService::rincian($status) : null;
    $kelas = \App\Services\PantauanStokService::kelasLampu($status);
@endphp

<span {{ $attributes->class(['status-stok', $kelas]) }}>
    <span class="status-stok-label"><span class="lampu-titik" aria-hidden="true"></span>{{ $label }}</span>
    @if ($rincian)
        <span class="status-stok-rinci">{{ $rincian }}</span>
    @endif
</span>
