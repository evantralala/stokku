{{--
    Kartu angka di atas daftar data master: total, aktif, tidak aktif, lalu kartu tambahan halaman itu (slot).
    Prop: label (mis. "bahan baku"), aktif, nonaktif (jumlah), ikon (ikon kartu total).
--}}
@props(['label', 'aktif', 'nonaktif', 'ikon' => 'kotak'])

<div {{ $attributes->class(['kartu-grid', 'kartu-grid-master']) }}>
    <x-kartu-angka warna="ungu" :ikon="$ikon" :label="'Total '.$label" :angka="$aktif + $nonaktif" :ket="$aktif.' aktif terdaftar'" />
    <x-kartu-angka warna="hijau" ikon="ceklis" label="Status Aktif" :angka="$aktif" nada="baik" ket="muncul di pilihan transaksi" />
    <x-kartu-angka warna="biru" ikon="kunci" label="Tidak Aktif" :angka="$nonaktif" ket="disimpan untuk riwayat" />
    {{ $slot }}
</div>
