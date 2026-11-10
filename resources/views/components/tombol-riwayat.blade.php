{{--
    Tombol Riwayat perubahan (popup). Prop: jenis (kunci DaftarRiwayat::JENIS), objek (id satu data, opsional), label (teks tombol).
    Di HP tombol di kepala halaman tampil sebagai ikon saja.
--}}
@props(['jenis', 'objek' => null, 'label' => 'Riwayat'])

<a {{ $attributes->class(['btn tombol-ikon-hp']) }} href="{{ route('admin-utama.riwayat', array_filter(['jenis' => $jenis, 'objek' => $objek])) }}"
   data-popup="panjang" aria-label="Riwayat perubahan">
    <x-ikon nama="riwayat" />
    <span>{{ \App\Support\Teks::judul($label) }}</span>
</a>
