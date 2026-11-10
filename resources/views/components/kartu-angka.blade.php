{{--
    Kartu angka ringkas bergradasi (dashboard, daftar, stok).
    Prop: label, angka, ket (keterangan kecil), tautan (opsional), nada: biasa | perhatian | bahaya | baik,
          ikon (nama di components/ikon, opsional),
          warna: ungu | biru | hijau | merah | jingga (opsional). Tanpa warna, gradasi mengikuti nada:
          bahaya = merah, perhatian = jingga, baik = hijau, biasa = biru/ungu bergantian.
          Dengan warna dan nada bahaya/perhatian, kartu diberi titik berdenyut sebagai tanda perlu dicek.
    Angka bersatuan (mis. "1.000 pasang") ditulis dengan satuan lebih kecil yang boleh turun ke baris berikutnya.
--}}
@props(['label', 'angka', 'ket' => null, 'tautan' => null, 'nada' => 'biasa', 'ikon' => null, 'warna' => null])

@php
    $tag = $tautan ? 'a' : 'div';
    $gradasi = $warna ?? ['bahaya' => 'merah', 'perhatian' => 'jingga', 'baik' => 'hijau'][$nada] ?? 'biru';
    $tanda = $warna && in_array($nada, ['bahaya', 'perhatian'], true);
    $bagian = is_scalar($angka) && preg_match('/^(\S*\d\S*)\s+(\D.*)$/u', (string) $angka, $cocok) ? [$cocok[1], $cocok[2]] : null;
@endphp

<{{ $tag }} @if ($tautan) href="{{ $tautan }}" @endif {{ $attributes->class(['kartu-angka', 'nada-'.$nada, 'gradasi-'.$gradasi, 'gradasi-otomatis' => ! $warna && $nada === 'biasa']) }}>
    @if ($ikon)
        <span class="kartu-ikon" aria-hidden="true"><x-ikon :nama="$ikon" width="18" height="18" /></span>
    @endif
    <span class="kartu-label">
        @if ($tanda)
            <span class="kartu-tanda" aria-hidden="true"></span>
        @endif
        {{ \App\Support\Teks::judul($label) }}
    </span>
    <span class="kartu-nilai num">
        @if ($bagian)
            <span class="kartu-nilai-angka">{{ $bagian[0] }}</span> <span class="kartu-satuan">{{ $bagian[1] }}</span>
        @else
            {{ $angka }}
        @endif
    </span>
    @if ($ket)
        <span class="kartu-ket">{{ $ket }}</span>
    @endif
</{{ $tag }}>
