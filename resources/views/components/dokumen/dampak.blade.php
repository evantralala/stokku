{{--
    Kotak catatan "Dampak ke Stok" di bawah daftar barang halaman detail dokumen.
    Prop: judul (bawaan "Dampak ke Stok"). Slot: penjelasan singkat.
--}}
@props(['judul' => 'Dampak ke Stok'])

<aside {{ $attributes->class('dampak-dokumen') }}>
    <x-ikon nama="peringatan" width="20" height="20" />
    <div>
        <h3 class="dampak-dokumen-judul">{{ $judul }}</h3>
        <p>{{ $slot }}</p>
    </div>
</aside>
