{{-- Form tambah / ubah satuan. Variabel dari SatuanController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Satuan</a>
@endsection
@section('judul', $item ? 'Ubah Satuan' : 'Tambah Satuan')
@section('sub', $item?->nama ?? '')

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="pendek">
        <form class="panel" method="POST"
              action="{{ $item ? route($rute.'.perbarui', $item) : route($rute.'.simpan') }}" novalidate>
            @csrf
            @if ($item)
                @method('PUT')
            @endif

            <div class="form-grid">
                <x-form.teks name="nama" label="Nama Satuan" :value="$item?->nama" wajib
                             :maxlength="$maksNama" autocomplete="off" autocapitalize="none"
                             :bantu="$item ? 'Langsung berubah di semua bahan yang memakainya.' : 'Mis. gram, kg, pcs.'"
                             placeholder="gram" />
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan Satuan'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item)
            <x-master.kelola :rute="$rute" :item="$item" label="satuan" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak muncul di pilihan satuan saat menambah bahan baku" />
        @endif
    </div>
@endsection