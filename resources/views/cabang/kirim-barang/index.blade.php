{{-- Kiriman keluar cabang (ke cabang lain atau retur ke Gudang Pusat). Variabel dari KirimBarangController@index: $daftar. --}}
@extends('layouts.app')

@section('judul', 'Kirim Barang')
@section('sub', 'Ke cabang lain atau retur ke Gudang Pusat')
@section('segar', 'ya')

@section('aksi')
    <x-tombol-unduh :href="route('cabang.kirim-barang.unduh')" periode />
    @boleh('kirim-barang.catat')
        <a class="btn btn-merah btn-tambah" href="{{ route('cabang.kirim-barang.buat') }}" data-popup="panjang">
            <x-ikon nama="plus" />
            Kirim
        </a>
    @endboleh
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="truk" label="Kiriman Keluar" :angka="$daftar->total()" ket="ke cabang lain dan retur" />
    </div>

    <section class="panel">
        <x-tabel-dokumen :kolom="['Tujuan', 'Dikirim']">
            @foreach ($daftar as $k)
                @php [$label, $kelas] = $k->tampilanStatus(); @endphp
                <x-baris-dokumen :href="route('cabang.kirim-barang.lihat', $k)" :nomor="$k->nomor"
                                 :sel="[$k->keCabang->nama, $k->dikirim_pada?->translatedFormat('j M, H.i')]"
                                 :label="$label" :kelas="$kelas" :jumlah="$k->detail_count.' barang'" />
            @endforeach
            <x-slot:kosong>Belum ada kiriman dari cabang ini.</x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
