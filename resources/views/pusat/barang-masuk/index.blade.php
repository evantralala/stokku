{{-- Daftar barang masuk Gudang Pusat. Variabel dari BarangMasukController@index. --}}
@extends('layouts.app')

@section('segar', 'ya')

@section('judul', 'Barang Masuk')

@section('aksi')
    <x-master.tombol-excel :impor="route('pusat.barang-masuk.impor')" :ekspor="route('pusat.barang-masuk.ekspor')" />
    <a class="btn btn-merah btn-tambah" href="{{ route('pusat.barang-masuk.catat') }}" data-popup="panjang">
        <x-ikon nama="plus" />
        Catat
    </a>
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="masuk" label="Dokumen Barang Masuk" :angka="$daftar->total()" :ket="$status === 'semua' ? 'semua status' : 'sesuai saringan status'" />
        <x-kartu-angka ikon="pensil" label="Perlu Diperbaiki" :angka="$perluDiperbaiki" :nada="$perluDiperbaiki > 0 ? 'bahaya' : 'baik'"
                       ket="dikembalikan Admin Utama" :tautan="route('pusat.barang-masuk', ['status' => \App\Models\BarangMasuk::STATUS_REVISI])" />
        <x-kartu-angka ikon="kotak" label="Barang di Halaman Ini" :angka="$daftar->getCollection()->sum('detail_count')" ket="baris bahan" />
    </div>

    @if ($perluDiperbaiki > 0)
        <div class="kilat kilat-galat" role="status">
            <x-ikon nama="peringatan" />
            <p>
                {{ $perluDiperbaiki }} dokumen perlu diperbaiki.
                <a class="link" href="{{ route('pusat.barang-masuk', ['status' => \App\Models\BarangMasuk::STATUS_REVISI]) }}">Buka</a>
            </p>
        </div>
    @endif

    <section class="panel">
        <div class="panel-head">
            <form class="alat" method="GET" action="{{ route('pusat.barang-masuk') }}">
                <label class="tersembunyi" for="filter-status">Status</label>
                <select class="isian" id="filter-status" name="status" data-kirim-otomatis>
                    <option value="semua">Semua Status</option>
                    @foreach ($pilihanStatus as $nilai => [$teks])
                        <option value="{{ $nilai }}" @selected($status === $nilai)>{{ $teks }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-sm">Tampilkan</button></noscript>
            </form>
        </div>

        <x-tabel-dokumen :kolom="['Tanggal', 'Pencatat']">
            @foreach ($daftar as $d)
                <x-baris-dokumen :href="route('pusat.barang-masuk.lihat', $d)" :nomor="$d->nomor"
                                 :sel="[$d->tanggal->translatedFormat('j M Y'), $d->pencatat->nama]"
                                 :label="$d->label_status" :kelas="$d->kelas_status" :jumlah="$d->detail_count.' barang'" />
            @endforeach
            <x-slot:kosong>
                @if ($status === 'semua')
                    Belum ada barang masuk.
                    <a class="link" href="{{ route('pusat.barang-masuk.catat') }}" data-popup="panjang">Catat yang pertama</a>
                @else
                    Tidak ada dokumen dengan status ini.
                    <a class="link" href="{{ route('pusat.barang-masuk') }}">Tampilkan semua</a>
                @endif
            </x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
