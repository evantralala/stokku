{{--
    Tombol besar di dashboard untuk tugas yang sering dikerjakan (gaya kasir).
    Prop: tautan, ikon (nama di components/ikon), label (1–3 kata), ket (keterangan pendek, opsional),
          angka (penanda merah di pojok bila > 0), utama (true = tombol utama bergaris merah).
--}}
@props(['tautan', 'ikon', 'label', 'ket' => null, 'angka' => 0, 'utama' => false])

<a href="{{ $tautan }}" {{ $attributes->class(['tombol-cepat', 'utama' => $utama]) }}>
    <span class="tombol-cepat-ikon" aria-hidden="true"><x-ikon :nama="$ikon" width="22" height="22" /></span>
    <span class="tombol-cepat-label">{{ \App\Support\Teks::judul($label) }}</span>
    @if ($ket)
        <span class="tombol-cepat-ket">{{ $ket }}</span>
    @endif
    @if ($angka > 0)
        <span class="tombol-cepat-angka num" aria-label="{{ $angka }} menunggu">{{ $angka > 99 ? '99+' : $angka }}</span>
    @endif
</a>
