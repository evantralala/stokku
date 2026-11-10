{{--
    Donat kondisi stok (habis · menipis · aman) dengan tiga angka lampu sebagai legenda yang bisa ditekan.
    Prop: jumlah ([lampu => angka], dari PantauanStokService::hitungPerLampu), rute atau jangkar (diteruskan ke x-lampu-kepala),
          judul, sub (opsional).
--}}
@props(['jumlah', 'rute' => null, 'jangkar' => null, 'judul' => 'Kondisi Stok', 'sub' => null])

@php
    $lampu = \App\Services\PantauanStokService::class;
    $warna = [$lampu::LAMPU_MERAH => 'var(--color-beni)', $lampu::LAMPU_KUNING => 'var(--color-yuzu)', $lampu::LAMPU_HIJAU => 'var(--color-matcha)'];
    $total = array_sum($jumlah);
    $irisan = [];
    $mulai = 0;
    foreach ($warna as $l => $w) {
        $akhir = $total > 0 ? $mulai + ($jumlah[$l] ?? 0) / $total * 100 : $mulai;
        $irisan[] = "{$w} {$mulai}% {$akhir}%";
        $mulai = $akhir;
    }
    $latar = $total > 0 ? 'conic-gradient('.implode(', ', $irisan).')' : 'var(--color-garis-2)';
    $persenAman = $total > 0 ? round(($jumlah[$lampu::LAMPU_HIJAU] ?? 0) / $total * 100) : 0;
@endphp

<section {{ $attributes->class(['panel', 'grafik-kartu', 'donat-kartu']) }}>
    <div class="panel-head">
        <div>
            <h2>{{ $judul }}</h2>
            @if ($sub)
                <div class="sub">{{ $sub }}</div>
            @endif
        </div>
    </div>

    <div class="donat-badan">
        <div class="donat" style="background: {{ $latar }}" role="img"
             aria-label="{{ $total }} bahan: {{ $jumlah[$lampu::LAMPU_MERAH] ?? 0 }} habis, {{ $jumlah[$lampu::LAMPU_KUNING] ?? 0 }} menipis, {{ $jumlah[$lampu::LAMPU_HIJAU] ?? 0 }} aman">
            <div class="donat-lubang">
                <span class="donat-angka num">{{ $persenAman }}%</span>
                <span class="donat-ket">aman</span>
            </div>
        </div>
        <x-lampu-kepala :jumlah="$jumlah" :rute="$rute" :jangkar="$jangkar" />
    </div>
</section>
