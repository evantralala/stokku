{{--
    Dashboard Admin Pusat: banner sapaan, kartu angka, tombol cepat, grafik pergerakan + donat kondisi stok,
    stok gudang yang perlu diisi. Variabel dari Pusat\DashboardController:
      $lokasi (null bila akun belum terhubung ke Gudang Pusat aktif), $bahanBermasalah, $masukMenunggu,
      $perluDiperbaiki, $jumlahPerLampu; $jumlah dan $pengguna dari LayoutComposer.
--}}
@extends('layouts.app')

@section('judul', 'Dashboard')
@section('segar', 'ya')
@section('sub', now()->translatedFormat('l, j F Y'))

@section('content')
    @if (! $lokasi)
        <div class="kilat kilat-galat" role="alert">
            <x-ikon nama="peringatan" />
            <p>Akun Anda belum terhubung ke Gudang Pusat yang aktif. Hubungi Admin Utama.</p>
        </div>
    @else
        @php
            $revisi = $perluDiperbaiki > 0;
            $merah = $jumlahPerLampu[\App\Services\PantauanStokService::LAMPU_MERAH];
            $kuning = $jumlahPerLampu[\App\Services\PantauanStokService::LAMPU_KUNING];
        @endphp

        @include('dashboard._sapaan', [
            'ruteCari' => \App\View\MenuSidebar::ruteCari($pengguna),
            'ringkas'  => $lokasi->nama.' · '.array_sum($jumlahPerLampu).' bahan dipantau, '.$merah.' habis, '.$kuning.' menipis · '
                .$jumlah['permintaan_belum_dikirim'].' permintaan siap dikirim.',
        ])

        <div class="kartu-grid">
            <x-kartu-angka warna="biru" ikon="truk" label="Siap Dikirim" :angka="$jumlah['permintaan_belum_dikirim']" :tautan="route('pusat.kiriman')"
                           :nada="$jumlah['permintaan_belum_dikirim'] > 0 ? 'perhatian' : 'biasa'"
                           :ket="$jumlah['kiriman_aktif'] > 0 ? $jumlah['kiriman_aktif'].' kiriman di jalan' : 'permintaan disetujui'" />
            <x-kartu-angka warna="ungu" ikon="masuk" label="Barang Masuk Menunggu" :angka="$masukMenunggu" :tautan="route('pusat.barang-masuk')"
                           ket="menunggu persetujuan" />
            <x-kartu-angka warna="jingga" ikon="pensil" label="Perlu Diperbaiki" :angka="$perluDiperbaiki"
                           :tautan="route('pusat.barang-masuk', ['status' => \App\Models\BarangMasuk::STATUS_REVISI])"
                           :nada="$revisi ? 'bahaya' : 'biasa'" ket="dikembalikan Admin Utama" />
            <x-kartu-angka ikon="kotak" label="Stok Habis / Menipis" :angka="$merah.' / '.$kuning" :tautan="route('pusat.stok')"
                           :nada="$merah > 0 ? 'bahaya' : ($kuning > 0 ? 'perhatian' : 'baik')"
                           :ket="$merah + $kuning > 0 ? 'perlu segera diisi' : 'Semua aman'" />
        </div>

        <nav class="tombol-cepat-grid" aria-label="Catat cepat">
            <x-tombol-cepat :tautan="route('pusat.kiriman')" ikon="truk" label="Kirim Barang"
                            :ket="$jumlah['permintaan_belum_dikirim'] > 0 ? $jumlah['permintaan_belum_dikirim'].' siap dikirim' : ($jumlah['kiriman_aktif'] > 0 ? $jumlah['kiriman_aktif'].' di jalan' : null)"
                            :angka="$jumlah['permintaan_belum_dikirim']" />
            <x-tombol-cepat :tautan="$revisi ? route('pusat.barang-masuk', ['status' => \App\Models\BarangMasuk::STATUS_REVISI]) : route('pusat.barang-masuk.catat')"
                            ikon="masuk" label="Barang Masuk" :data-popup="$revisi ? null : 'panjang'"
                            :ket="$revisi ? $perluDiperbaiki.' dikembalikan' : ($masukMenunggu > 0 ? $masukMenunggu.' menunggu' : null)"
                            :angka="$perluDiperbaiki" />
            @boleh('barang-keluar.catat')
                <x-tombol-cepat :tautan="route('pusat.barang-keluar.catat')" ikon="keluar" label="Barang Rusak" data-popup="panjang" />
            @endboleh
            @boleh('opname.lihat')
                <x-tombol-cepat :tautan="route('pusat.opname')" ikon="ceklis" label="Opname" />
            @endboleh
            @if (app(\App\Services\KontrolSistemService::class)->bolehRute(auth()->user(), 'admin-utama.impor-sekaligus'))
                <x-tombol-cepat :tautan="route('admin-utama.impor-sekaligus')" ikon="keluar" label="Impor Sekaligus" data-popup />
            @endif
        </nav>

        <div class="dasbor-grafik">
            <x-grafik.pergerakan :lokasi="$lokasi" />
            <x-grafik.kondisi :jumlah="$jumlahPerLampu" rute="pusat.stok" :sub="$lokasi->nama" />
        </div>

        <div class="dasbor-dua">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2><x-ikon-judul nama="kotak" />Perlu Diisi</h2>
                        <div class="sub">
                            @if ($jumlahPerLampu[\App\Services\PantauanStokService::LAMPU_MERAH] + $jumlahPerLampu[\App\Services\PantauanStokService::LAMPU_KUNING] > 0)
                                <x-ringkas-lampu :jumlah="\Illuminate\Support\Arr::except($jumlahPerLampu, \App\Services\PantauanStokService::LAMPU_HIJAU)" />
                            @else
                                Semua aman
                            @endif
                        </div>
                    </div>
                </div>
                @include('dashboard._bahan', ['daftar' => $bahanBermasalah, 'ruteStok' => route('pusat.stok'), 'ruteRiwayat' => 'pusat.stok.riwayat'])
            </section>
        </div>
    @endif
@endsection
