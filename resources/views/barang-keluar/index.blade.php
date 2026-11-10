{{-- Daftar barang rusak (Admin Pusat dan cabang). Variabel dari KelolaBarangKeluar::index(): $daftar, $rute, $lokasi, $perluDiperbaiki. --}}
@extends('layouts.app')

@section('segar', 'ya')

@section('judul', 'Barang Rusak')

@section('aksi')
    <x-tombol-unduh :href="route($rute.'.unduh')" periode />
    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.catat') }}" data-popup="panjang">
        <x-ikon nama="plus" />
        Catat
    </a>
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="keluar" label="Dokumen Barang Rusak" :angka="$daftar->total()" :ket="$lokasi->nama" />
        <x-kartu-angka ikon="pensil" label="Perlu Diperbaiki" :angka="$perluDiperbaiki" :nada="$perluDiperbaiki > 0 ? 'bahaya' : 'baik'" ket="dikembalikan Admin Utama" />
    </div>

    @if ($perluDiperbaiki > 0)
        <div class="kilat kilat-galat" role="status">
            <x-ikon nama="peringatan" />
            <p>{{ $perluDiperbaiki }} dokumen perlu diperbaiki. Buka yang berstatus <strong>Perlu diperbaiki</strong>.</p>
        </div>
    @endif

    <section class="panel">
        <x-tabel-dokumen :kolom="['Tanggal', 'Pencatat']">
            @foreach ($daftar as $d)
                <x-baris-dokumen :href="route($rute.'.lihat', $d)" :nomor="$d->nomor"
                                 :sel="[$d->tanggal->translatedFormat('j M Y'), $d->pencatat->nama]"
                                 :label="$d->label_status" :kelas="$d->kelas_status" :jumlah="$d->detail_count.' barang'" />
            @endforeach
            <x-slot:kosong>
                Belum ada barang rusak.
                <a class="link" href="{{ route($rute.'.catat') }}" data-popup="panjang">Catat yang pertama</a>
            </x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
