{{--
    Tombol ikon di kolom aksi daftar: biru untuk Ubah (pensil), ungu untuk tautan lain (mis. Resep). Teks tampil sebagai tooltip dan untuk pembaca layar.
    Prop: href, nama (nama data, untuk label), popup ('' = popup pendek, 'panjang', atau false = halaman biasa),
          ikon (bawaan pensil), teks (bawaan "Ubah").
--}}
@props(['href', 'nama', 'popup' => '', 'ikon' => 'pensil', 'teks' => 'Ubah'])

<a {{ $attributes->class(['btn', 'btn-sm', 'tombol-ikon-aksi', $teks === 'Ubah' ? 'tombol-ikon-ubah' : 'tombol-ikon-lain']) }} href="{{ $href }}"
   @if ($popup !== false) data-popup{!! $popup !== '' ? '="'.e($popup).'"' : '' !!} @endif
   title="{{ $teks }}" aria-label="{{ $teks }} {{ $nama }}">
    <x-ikon :nama="$ikon" width="18" height="18" />
</a>
