{{--
    Kiriman yang datang ke lokasi ini (cabang, atau Gudang Pusat untuk retur). Variabel dari KelolaTerimaBarang::index: $daftar, $rute, $lokasi.
    Kiriman yang belum diterima bisa dicentang lalu diterima sekaligus "sesuai semua" lewat bilah bawah.
--}}
@extends('layouts.app')

@section('judul', 'Terima Barang')
@section('segar', 'ya')

@section('aksi')
    <x-tombol-unduh :href="route($rute.'.unduh')" periode />
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="masuk" label="Kiriman Masuk" :angka="$daftar->total()" ket="ke lokasi ini" />
        <x-kartu-angka ikon="ceklis" label="Belum Diterima" :angka="$daftar->getCollection()->filter->menungguDiterima()->count()"
                       :nada="$daftar->getCollection()->contains(fn ($k) => $k->menungguDiterima()) ? 'perhatian' : 'baik'" ket="di halaman ini" />
    </div>

    <section class="panel">
        @php $adaMenunggu = $daftar->getCollection()->contains(fn ($k) => $k->menungguDiterima()); @endphp
        <x-tabel-dokumen :kolom="['Dari', 'Dikirim']" :pilih="$adaMenunggu ? 'pilih-terima' : null">
            @foreach ($daftar as $k)
                @php [$label, $kelas] = $k->tampilanStatus(); @endphp
                <x-baris-dokumen :href="route($rute.'.lihat', $k)" :nomor="$k->nomor"
                                 :sel="[$k->dariCabang?->nama, $k->dikirim_pada?->translatedFormat('j M, H.i')]"
                                 :label="$label" :kelas="$kelas" :jumlah="$k->detail_count.' barang'"
                                 :sorot="$k->menungguDiterima() ? 'Hitung sekarang' : null"
                                 :popup="$k->menungguDiterima() ? 'panjang' : null"
                                 :pilih="! $adaMenunggu ? null : ($k->menungguDiterima() ? ['form' => 'pilih-terima', 'name' => 'kiriman[]', 'value' => $k->id] : [])" />
            @endforeach
            <x-slot:kosong>Belum ada kiriman.</x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$daftar" />

        @if ($adaMenunggu)
            <x-pilih.bilah id="pilih-terima" :action="route($rute.'.banyak.simpan')" tombol="Terima yang Dicentang" hijau
                           :konfirmasi="'Semua barang di kiriman yang dicentang dianggap sesuai dan masuk ke stok '.$lokasi->nama.'. Ada yang kurang atau rusak? Buka kiriman itu dan pilih Ada yang Beda.'"
                           judul-konfirmasi="Terima Kiriman yang Dicentang?" tombol-konfirmasi="Ya, Terima" />
        @endif
    </section>
@endsection
