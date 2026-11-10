{{-- Form tambah / ubah menu. Variabel dari MenuController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Menu</a>
@endsection
@section('judul', $item ? 'Ubah Menu' : 'Tambah Menu')
@section('sub', $item?->nama ?? '')

@section('aksi')
    @if ($item)
        <a class="btn" href="{{ route($rute.'.resep', $item) }}">Lihat Resep</a>
    @endif
@endsection

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="panjang">
        @unless ($pilihanKategori)
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="peringatan" />
                <p>Belum ada kategori menu. <a class="link" href="{{ route('admin-utama.kategori-menu.tambah') }}" data-popup>Tambah dulu</a></p>
            </div>
        @endunless

        <form class="panel" method="POST"
              action="{{ $item ? route($rute.'.perbarui', $item) : route($rute.'.simpan') }}" enctype="multipart/form-data" novalidate>
            @csrf
            @if ($item)
                @method('PUT')
            @endif

            <div class="form-grid">
                <x-form.teks name="nama" label="Nama Menu" :value="$item?->nama" wajib lebar
                             :maxlength="$maks['nama']" autocomplete="off" placeholder="Contoh: Shoyu Ramen" />

                <x-form.foto :src="$item?->urlFoto()" :nama="$item?->nama ?? ''" />

                <x-form.pilihan name="kategori_menu_id" label="Kategori Menu" :pilihan="$pilihanKategori"
                                :value="$item?->kategori_menu_id" kosong="Pilih Kategori" wajib />
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan & Isi Resep'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item)
            <x-master.kelola :rute="$rute" :item="$item" label="menu" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak muncul di form penjualan harian" />
        @endif
    </div>
@endsection
