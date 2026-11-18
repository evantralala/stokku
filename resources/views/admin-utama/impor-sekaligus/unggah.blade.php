{{--
    Unggah file Impor Sekaligus (popup dari dashboard). $pilihanMode: [kunci => [label]] untuk lembar Stok, $mode: bawaan.
    Penjelasan tiap lembar ada di lembar Petunjuk pada templat, tidak diulang di sini.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route(auth()->user()->ruteDashboard()) }}">Dashboard</a>
@endsection
@section('judul', 'Impor Sekaligus')

@section('content')
    <section class="panel impor-excel" data-popup-isi>
        <div class="kontrol-isi">
            @include('admin-utama.excel._form-unggah', [
                'ket' => 'Isi lembar yang diperlukan saja, lembar lain boleh dikosongkan.',
                'templat' => route('admin-utama.impor-sekaligus.templat'), 'labelTemplat' => 'Unduh Templat',
                'aksi' => route('admin-utama.impor-sekaligus.pratinjau'),
                'labelMode' => 'Cara Simpan Stok', 'terima' => '.xlsx,.xls', 'teksFile' => 'Pilih File Excel',
            ])
        </div>
    </section>
@endsection
