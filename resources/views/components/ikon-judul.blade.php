{{-- Ikon kecil berlatar di depan judul panel (<h2>). Prop: nama (kunci di components/ikon). --}}
@props(['nama'])

<span class="ikon-judul" aria-hidden="true"><x-ikon :nama="$nama" /></span>
