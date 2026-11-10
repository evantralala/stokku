{{--
    Satu baris dokumen di <x-tabel-dokumen>: nomor (tautan) di kolom pertama, isi kolom tambahan sesuai urutan judul,
    jumlah barang, lalu status. Seluruh baris bisa ditekan; prop popup (mis. "panjang") membuka dokumen sebagai popup.
    Prop sorot: teks penting di bawah nomor (mis. "Hitung sekarang").
    Prop pilih: untuk tabel ber-pilih, ['form' => id bilah, 'name' => ..., 'value' => ...] = kotak centang, [] = sel kosong; null = tanpa kolom.
--}}
@props(['href', 'nomor', 'sel' => [], 'label', 'kelas', 'jumlah' => null, 'popup' => null, 'sorot' => null, 'pilih' => null])

<tr>
    <td class="kartu-judul" data-label="Nomor">
        <a class="tautan-baris nama-barang nomor-dokumen" href="{{ $href }}"@if ($popup) data-popup="{{ $popup }}"@endif>{{ $nomor }}</a>
        @if ($sorot)
            <span class="barang-ket teks-kurang">{{ $sorot }}</span>
        @endif
    </td>
    @foreach ($sel as $nilai)
        <td data-label="">{{ $nilai }}</td>
    @endforeach
    @if ($jumlah !== null)
        <td class="ka num" data-label="">{{ $jumlah }}</td>
    @endif
    <td class="kartu-utama" data-label=""><span class="badge {{ $kelas }}">{{ $label }}</span></td>
    @if (is_array($pilih))
        <td class="ka sel-pilih" data-label="">
            @if ($pilih !== [])
                <x-pilih.kotak :form="$pilih['form']" :name="$pilih['name']" :value="$pilih['value']" :label="$nomor" />
            @endif
        </td>
    @endif
</tr>
