{{--
    Tutup buku (Mulai Periode Baru). Dibuka sebagai popup dari Pengaturan > Periode Buku (bagian data-popup-isi), atau halaman biasa tanpa JavaScript.
    Variabel dari PeriodeBukuController@form: $penghalang (alasan belum bisa), $mulai (awal periode berjalan), $usulNama, $kata, $maksNama.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('admin-utama.pengaturan') }}">Pengaturan</a>
@endsection
@section('judul', 'Mulai Periode Baru')

@section('content')
    <form class="panel" method="POST" action="{{ route('admin-utama.kontrol-sistem.periode.simpan') }}" novalidate data-popup-isi>
        @csrf

        <div class="form-grid">
            @if ($penghalang !== [])
                <div class="field lebar kilat kilat-galat" role="alert">
                    <x-ikon nama="peringatan" />
                    <div>
                        <p><strong>Belum bisa tutup buku.</strong> Selesaikan dulu:</p>
                        <ul class="periode-penghalang">
                            @foreach ($penghalang as $alasan)
                                <li>{{ $alasan }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <ol class="field lebar langkah-ringkas periode-langkah">
                <li>Semua transaksi{{ $mulai ? ' sejak '.$mulai->translatedFormat('j M Y') : '' }} disimpan ke file Excel dan cadangan database.</li>
                <li>Transaksi, riwayat stok, dan notifikasi dikosongkan.</li>
                <li>Bahan, menu, resep, lokasi, akun, dan pengaturan tetap.</li>
                <li>Stok terakhir tiap lokasi menjadi stok awal periode baru.</li>
            </ol>

            <p class="field lebar satuan">Lakukan setelah penjualan hari ini dicatat semua. Penjualan yang sudah diarsipkan tidak bisa dicatat lagi.</p>

            @if ($penghalang === [])
                <x-form.teks name="nama" label="Nama Periode" :value="$usulNama" :maxlength="$maksNama" wajib lebar />
                <x-form.teks name="konfirmasi" :label="'Ketik '.$kata" wajib autocomplete="off"
                             autocapitalize="characters" spellcheck="false" :placeholder="$kata" />
                <x-form.teks name="password" type="password" label="Password Anda" wajib autocomplete="current-password" />
            @endif
        </div>

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ route('admin-utama.pengaturan') }}" data-popup-batal>{{ $penghalang === [] ? 'Batal' : 'Tutup' }}</a>
                @if ($penghalang === [])
                    <button type="submit" class="btn btn-merah">
                        <x-ikon nama="kalender" />
                        Tutup Buku
                    </button>
                @endif
            </div>
        </div>
    </form>
@endsection
