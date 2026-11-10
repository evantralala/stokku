{{--
    Pindah Gudang Pusat ke lokasi baru. Dibuka sebagai popup dari halaman Lokasi, atau halaman biasa tanpa JavaScript.
    Variabel: $pusat (Gudang Pusat aktif), $ringkasan (PindahGudangPusatService::ringkasan), $maks.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('admin-utama.lokasi') }}">Lokasi</a>
@endsection
@section('judul', 'Pindah Gudang Pusat')
@section('sub', 'Dari '.$pusat->nama)

@section('content')
    <form class="panel" method="POST" action="{{ route('admin-utama.lokasi.pindah-pusat.simpan') }}" novalidate data-popup-isi
          data-konfirmasi="Stok, Admin Pusat, dan stok minimum {{ $pusat->nama }} dipindah ke gudang baru, lalu {{ $pusat->nama }} dinonaktifkan."
          data-konfirmasi-judul="Pindahkan Gudang Pusat?"
          data-konfirmasi-tombol="Ya, Pindahkan">
        @csrf

        <div class="form-grid">
            @if ($ringkasan['hambatan'] !== [])
                <div class="field lebar kilat kilat-galat pindah-peringatan" role="alert">
                    <x-ikon nama="peringatan" />
                    <div>
                        <p><strong>Belum bisa dipindah.</strong> Selesaikan dulu:</p>
                        <ul class="pindah-daftar">
                            @foreach ($ringkasan['hambatan'] as $h)
                                <li>{{ $h }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="field lebar">
                <p class="pindah-judul">Yang ikut pindah ke gudang baru</p>
                <ul class="pindah-daftar">
                    <li><strong>{{ $ringkasan['bahan'] }} bahan</strong> beserta stoknya, lewat satu kiriman yang langsung diterima.</li>
                    <li><strong>{{ $ringkasan['admin'] }} Admin Pusat</strong>; mereka tetap masuk dengan akun yang sama.</li>
                    <li><strong>{{ $ringkasan['minimum'] }} stok minimum</strong>.</li>
                    @if ($ringkasan['minus'] > 0)
                        <li>{{ $ringkasan['minus'] }} bahan yang stoknya minus <strong>tidak</strong> ikut dipindah.</li>
                    @endif
                </ul>
                <p class="pindah-ket">{{ $pusat->nama }} lalu dinonaktifkan. Riwayat stok dan dokumennya tetap tersimpan.</p>
            </div>

            <x-form.teks name="nama" label="Nama Gudang Baru" wajib lebar :maxlength="$maks['nama']" autocomplete="off"
                         placeholder="Contoh: Gudang Pusat Baru" />
            <x-form.teks name="kode" label="Kode Singkat" wajib :maxlength="$maks['kode']" autocomplete="off"
                         autocapitalize="characters" class="huruf-besar" bantu="Muncul di nomor dokumen. Harus beda dari kode lama."
                         placeholder="GP2" />
            <x-form.teks name="alamat" label="Alamat" lebar :maxlength="$maks['alamat']"
                         placeholder="Contoh: Jl. Merdeka No. 10, Jakarta" />
            <x-form.teks name="password" type="password" label="Password Anda" wajib autocomplete="current-password" />
        </div>

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ route('admin-utama.lokasi') }}" data-popup-batal>Batal</a>
                <button type="submit" class="btn btn-merah" @disabled($ringkasan['hambatan'] !== [])>
                    <x-ikon nama="truk" />
                    Pindahkan
                </button>
            </div>
        </div>
    </form>
@endsection
