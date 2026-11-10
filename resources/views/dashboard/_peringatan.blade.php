{{-- Satu baris peringatan dini: titik warna per tingkat, judul, lalu tingkat · lokasi · keterangan. Variabel: $p (baris PeringatanDiniService), $pilihanTingkat. --}}
@php
    [$labelTingkat] = $pilihanTingkat[$p['tingkat']];
    $kelasLampu = \App\Services\PeringatanDiniService::LAMPU_TINGKAT[$p['tingkat']];
@endphp
<{{ $p['tautan'] ? 'a' : 'div' }} class="antrean peringatan {{ $kelasLampu }}" @if ($p['tautan']) href="{{ $p['tautan'] }}" @endif>
    <div class="antrean-isi">
        <div class="antrean-judul">{{ $p['judul'] }}</div>
        <div class="antrean-ket"><span class="teks-tingkat">{{ $labelTingkat }}</span> · {{ $p['lokasi'] }} · {{ $p['keterangan'] }}</div>
    </div>
    @if ($p['tautan'])
        <x-ikon nama="panah-kanan" class="antrean-panah" />
    @endif
</{{ $p['tautan'] ? 'a' : 'div' }}>
