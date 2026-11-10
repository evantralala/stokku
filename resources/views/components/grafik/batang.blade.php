{{--
    Grafik batang SVG tanpa JavaScript, dipakai grafik pergerakan dan penjualan.
    Prop: data (list ['label', 'judul', 'sumbu' (label tampil di bawah batang), lalu angka per seri]),
          seri ([kunci => [label, kelas batang]]), ringkas (keterangan untuk pembaca layar), judulTabel,
          lebar (lebar gambar; 960 untuk grafik selebar halaman supaya tidak terlalu tinggi di laptop).
    Angka yang sama tersedia sebagai tabel tersembunyi untuk pembaca layar.
--}}
@props(['data', 'seri', 'ringkas', 'judulTabel' => 'Rincian grafik', 'lebar' => 640])

@php
    $tertinggi = max(1, ...array_map(fn ($b) => max(array_map(fn ($k) => $b[$k], array_keys($seri))), $data));
    $langkah = max(1, (int) ceil($tertinggi / 4));
    $langkah = $langkah > 5 ? (int) (ceil($langkah / 5) * 5) : $langkah;
    $puncak = $langkah * 4;
    [$tinggi, $bawah, $atas] = [250, 36, 12];
    $huruf = $lebar > 640 ? 15 : 12;
    $kiri = max(36, 16 + $huruf * mb_strlen(\App\Support\Angka::tampil($puncak)));
    $ruang = ($lebar - $kiri) / max(1, count($data));
    $jarak = $ruang > 30 ? 4 : 1;
    $batang = min(26, ($ruang - $jarak * count($seri)) / (count($seri) + 0.6));
    $y = fn (int $n) => round($tinggi - $bawah - ($tinggi - $bawah - $atas) * $n / $puncak, 1);
@endphp

<svg @class(["grafik-batang", "grafik-lebar" => $lebar > 640]) viewBox="0 0 {{ $lebar }} {{ $tinggi }}" role="img" aria-label="{{ $ringkas }}">
    <defs>
        <linearGradient id="gradasi-batang-masuk" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#ff5a52"/>
            <stop offset="1" stop-color="#b5160f"/>
        </linearGradient>
        <linearGradient id="gradasi-batang-keluar" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#ffc65c"/>
            <stop offset="1" stop-color="#d97706"/>
        </linearGradient>
        <linearGradient id="gradasi-batang-porsi" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#4ade80"/>
            <stop offset="1" stop-color="#15803d"/>
        </linearGradient>
    </defs>
    @for ($i = 0; $i <= 4; $i++)
        @php $n = $langkah * $i; @endphp
        <line class="grafik-garis" x1="{{ $kiri }}" x2="{{ $lebar }}" y1="{{ $y($n) }}" y2="{{ $y($n) }}" />
        <text class="grafik-sumbu" x="{{ $kiri - 8 }}" y="{{ $y($n) + 4 }}" text-anchor="end">{{ \App\Support\Angka::tampil($n) }}</text>
    @endfor
    @foreach ($data as $i => $b)
        @php
            $tengah = $kiri + $ruang * ($i + 0.5);
            $mulai = $tengah - (count($seri) * $batang + (count($seri) - 1) * $jarak) / 2;
        @endphp
        @foreach (array_keys($seri) as $j => $k)
            @continue($b[$k] <= 0)
            <rect class="{{ $seri[$k][1] }}" x="{{ round($mulai + $j * ($batang + $jarak), 1) }}" y="{{ $y($b[$k]) }}" width="{{ round($batang, 1) }}"
                  height="{{ round($tinggi - $bawah - $y($b[$k]), 1) }}" rx="{{ $batang > 10 ? 4 : 2 }}"><title>{{ $b['judul'] }}: {{ \App\Support\Angka::tampil($b[$k]) }} {{ mb_strtolower($seri[$k][0]) }}</title></rect>
        @endforeach
        @if ($b['sumbu'])
            <text class="grafik-sumbu" x="{{ round($tengah, 1) }}" y="{{ $tinggi - 8 }}" text-anchor="middle">{{ $b['label'] }}</text>
        @endif
    @endforeach
</svg>

<div class="tersembunyi">
    <table>
        <caption>{{ $judulTabel }}</caption>
        <thead><tr><th>Waktu</th>@foreach ($seri as [$label])<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($data as $b)
                <tr><td>{{ $b['judul'] }}</td>@foreach (array_keys($seri) as $k)<td>{{ $b[$k] }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</div>
