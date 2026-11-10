{{-- Daftar laporan Admin Utama. Variabel dari LaporanController@index: $jenis ([kunci => [judul, keterangan]]), $ikon ([kunci => nama ikon]). --}}
@extends('layouts.app')

@section('judul', 'Laporan')

@section('content')
    <nav class="tombol-cepat-grid laporan-grid" aria-label="Pilih laporan">
        @foreach ($jenis as $kunci => [$judul, $keterangan])
            <x-tombol-cepat :tautan="route('admin-utama.laporan.'.$kunci)" :ikon="$ikon[$kunci] ?? 'grafik'"
                            :label="$judul" :ket="$keterangan" />
        @endforeach
    </nav>
@endsection
