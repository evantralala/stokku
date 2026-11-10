{{-- Riwayat perubahan satu fitur atau satu data (popup). Variabel dari RiwayatController: $judul, $daftar (paginator LogPerubahan), $kembali. --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ $kembali }}">Kembali</a>
@endsection
@section('judul', $judul)
@section('sub', $daftar->total().' catatan')

@section('content')
    <section class="panel" data-popup-isi>
        @include('admin-utama.kontrol-sistem._log', ['daftar' => $daftar])

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
