{{--
    Jumlah bahan per lampu, mis. "2 habis · 3 menipis" dengan titik warna.
    Prop: jumlah ([lampu => angka]); lampu yang nol atau tidak disertakan dilewati.
--}}
@props(['jumlah'])

<span {{ $attributes->class('ringkas-lampu') }}>
    @foreach (\App\Services\PantauanStokService::TAMPILAN_LAMPU as $lampu => [$label, $kelas])
        @if (($jumlah[$lampu] ?? 0) > 0)
            <span class="ringkas-lampu-isi {{ $kelas }}"><span class="lampu-titik" aria-hidden="true"></span>{{ $jumlah[$lampu] }} {{ mb_strtolower($label) }}</span>
        @endif
    @endforeach
</span>
