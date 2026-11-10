{{-- Form tambah / ubah kategori menu. Variabel dari KategoriMenuController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Kategori menu</a>
@endsection
@section('judul', $item ? 'Ubah Kategori Menu' : 'Tambah Kategori Menu')
@section('sub', $item ? $item->nama : 'Pengelompokan menu di form penjualan harian dan laporan.')

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="pendek">
        <form class="panel" method="POST"
              action="{{ $item ? route($rute.'.perbarui', $item) : route($rute.'.simpan') }}" novalidate>
            @csrf
            @if ($item)
                @method('PUT')
            @endif

            <div class="form-grid">
                <x-form.teks name="nama" label="Nama Kategori" :value="$item?->nama" wajib
                             :maxlength="$maksNama" autocomplete="off" placeholder="Contoh: Ramen" />

                <x-isian-jumlah name="urutan" label="Urutan Tampil" :value="$item?->urutan ?? $urutanBerikutnya" wajib
                              maxlength="4" bantu="Angka lebih kecil tampil lebih dulu." />
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan Kategori'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item)
            <x-master.kelola :rute="$rute" :item="$item" label="kategori menu" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak muncul di pilihan kategori saat menambah menu" />
        @endif
    </div>
@endsection
