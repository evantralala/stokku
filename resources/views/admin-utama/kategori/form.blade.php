{{-- Form tambah / ubah kategori bahan. Variabel dari KategoriController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Kategori bahan</a>
@endsection
@section('judul', $item ? 'Ubah Kategori' : 'Tambah Kategori')
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
                <x-form.teks name="nama" label="Nama Kategori" :value="$item?->nama" wajib
                             :maxlength="$maksNama" autocomplete="off" placeholder="Contoh: Sayur" />

                <x-isian-jumlah name="urutan" label="Urutan Tampil" :value="$item?->urutan ?? $urutanBerikutnya" wajib
                                maxlength="4" bantu="Kecil tampil lebih dulu." />
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan Kategori'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item)
            <x-master.kelola :rute="$rute" :item="$item" label="kategori bahan" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak muncul di pilihan kategori saat menambah bahan baku" />
        @endif
    </div>
@endsection