{{--
    Kartu kepala halaman detail dokumen (ala "Detail transaksi"): ikon jenis, nomor, keterangan singkat, badge status di kanan,
    lalu strip info berisi pasangan label dan nilai (lokasi, tanggal, pencatat, dst.).
    Prop: ikon (nama di components/ikon), nada (masuk | keluar | netral: warna ikon), nomor, jenis, ket (teks kecil di bawah nomor),
          info ([label => nilai]; nilai null atau '' dilewati; boleh HtmlString). Slot status: badge status dokumen.
--}}
@props(['ikon' => 'kotak', 'nada' => 'netral', 'nomor', 'jenis', 'ket' => null, 'info' => []])

@php $info = array_filter($info, fn ($nilai) => $nilai !== null && $nilai !== ''); @endphp

<section {{ $attributes->class(['panel', 'kepala-dokumen', 'nada-'.$nada]) }}>
    <div class="kepala-dokumen-atas">
        <span class="kepala-dokumen-ikon" aria-hidden="true"><x-ikon :nama="$ikon" width="22" height="22" /></span>
        <div class="kepala-dokumen-teks">
            <div class="kepala-dokumen-nomor nomor-dokumen">{{ $nomor }}</div>
            <div class="kepala-dokumen-ket">{{ $jenis }}@if ($ket) · {{ $ket }}@endif</div>
        </div>
        @isset($status)
            <div class="kepala-dokumen-status">{{ $status }}</div>
        @endisset
    </div>

    @if (count($info) > 0)
        <dl class="kepala-dokumen-info">
            @foreach ($info as $label => $nilai)
                <div>
                    <dt>{{ $label }}</dt>
                    <dd>{{ $nilai }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</section>
