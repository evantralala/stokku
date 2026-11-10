{{--
    Satu baris antrean persetujuan (di dalam _tabel-antrean). Variabel: $a (baris AntreanPersetujuanService, dengan 'cek'), $pilihanJenis.
    Dokumen wajar bisa langsung disetujui (dua tekan: Setujui → konfirmasi); lainnya dibuka untuk diperiksa.
--}}
@php
    $cek = $a['cek'];
    $kelasLampu = $cek['wajar'] ? 'lampu-hijau' : ($cek['massal'] ? 'lampu-kuning' : 'lampu-merah');
@endphp
<tr class="{{ $kelasLampu }}">
    <td class="kartu-judul" data-label="Dokumen">
        <a class="tautan-baris nama-barang nomor-dokumen" href="{{ $a['tautan'] }}">{{ $a['nomor'] }}</a>
        <span class="barang-ket">{{ $pilihanJenis[$a['jenis']] }}{{ $a['otomatis'] ? ' · otomatis' : '' }} · {{ $a['keterangan'] }}</span>
        @if ($ringkas ?? false)
            <span class="barang-ket">{{ $a['lokasi'] }}@if ($a['batas']) · <span @class(['antrean-batas', 'mendesak' => \App\Services\AntreanPersetujuanService::mendesak($a)])>sebelum {{ $a['batas']->translatedFormat('j M, H.i') }}</span>@endif</span>
        @endif
    </td>
    @unless ($ringkas ?? false)
    <td data-label="Lokasi">{{ $a['lokasi'] }}</td>
    <td data-label="Batas">
        @if ($a['batas'])
            <span @class(['antrean-batas', 'mendesak' => \App\Services\AntreanPersetujuanService::mendesak($a)])>
                {{ $a['batas']->translatedFormat('j M, H.i') }}
            </span>
        @elseif ($a['mendesak'])
            <span class="antrean-batas mendesak">Mendesak</span>
        @else
            —
        @endif
    </td>
    @endunless
    <td class="kartu-lebar" data-label="">
        <span class="cek-wajar {{ $kelasLampu }}">
            <span class="lampu-titik" aria-hidden="true"></span>{{ $cek['wajar'] ? 'Wajar' : ($cek['massal'] ? 'Cek: '.$cek['alasan'] : $cek['alasan']) }}
        </span>
    </td>
    <td class="ka kartu-aksi antrean-aksi" data-label="">
        @if ($cek['wajar'])
            <form method="POST" action="{{ route('admin-utama.persetujuan.wajar.setujui') }}"
                  data-konfirmasi="{{ $pilihanJenis[$a['jenis']] }} {{ $a['nomor'] }} dari {{ $a['lokasi'] }} akan disetujui."
                  data-konfirmasi-judul="Setujui {{ $a['nomor'] }}?"
                  data-konfirmasi-tombol="Ya, Setujui">
                @csrf
                <input type="hidden" name="dokumen[]" value="{{ $a['kunci'] }}">
                <button type="submit" class="btn btn-sm btn-hijau">Setujui</button>
            </form>
        @else
            <a class="btn btn-sm btn-garis" href="{{ $a['tautan'] }}">Periksa</a>
        @endif
    </td>
    @if ($pilih ?? null)
        <td class="ka sel-pilih" data-label="">
            @if ($cek['wajar'])
                <x-pilih.kotak :form="$pilih" name="dokumen[]" :value="$a['kunci']" :label="$a['nomor']" />
            @endif
        </td>
    @endif
</tr>
