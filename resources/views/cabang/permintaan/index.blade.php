{{-- Daftar permintaan cabang. Variabel dari PermintaanController@index: $daftar, $jumlahUsulan. --}}
@extends('layouts.app')

@section('judul', 'Permintaan')
@section('segar', 'ya')

@section('aksi')
    <x-tombol-unduh :href="route('cabang.permintaan.unduh')" periode />
    @boleh('permintaan.catat')
        <x-master.tombol-excel :impor="route('cabang.permintaan.impor')" label="permintaan" />
        <a class="btn btn-merah btn-tambah" href="{{ route('cabang.permintaan.buat') }}" data-popup="panjang">
            <x-ikon nama="plus" />
            Buat
        </a>
    @endboleh
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="panah-kiri" label="Permintaan" :angka="$daftar->total()" ket="semua permintaan cabang" />
        <x-kartu-angka ikon="peringatan" label="Perlu Diminta" :angka="$jumlahUsulan" :nada="$jumlahUsulan > 0 ? 'perhatian' : 'baik'" ket="bahan di bawah minimum" />
    </div>

    @if ($jumlahUsulan > 0)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>
                {{ $jumlahUsulan }} bahan di bawah stok minimum dan belum diminta.
                @boleh('permintaan.catat')
                    <a class="link" href="{{ route('cabang.permintaan.buat', ['usulan' => 1]) }}" data-popup="panjang">Minta sekarang</a>
                @else
                    Permintaan dibuat otomatis oleh sistem.
                @endboleh
            </p>
        </div>
    @endif

    <section class="panel">
        <x-tabel-dokumen :kolom="['Diajukan', 'Sumber']">
            @foreach ($daftar as $p)
                @php
                    [$labelStatus, $kelasStatus] = $p->tampilanStatus();
                    $sumber = $p->otomatis() ? 'Otomatis' : 'Manual';
                @endphp
                <x-baris-dokumen :href="route('cabang.permintaan.lihat', $p)" :nomor="$p->nomor"
                                 :sel="[$p->diajukan_pada?->translatedFormat('j M, H.i'), $sumber]"
                                 :label="$labelStatus" :kelas="$kelasStatus" :jumlah="$p->detail_count.' barang'" />
            @endforeach
            <x-slot:kosong>
                Belum ada permintaan.
                @boleh('permintaan.catat')
                    <a class="link" href="{{ route('cabang.permintaan.buat') }}" data-popup="panjang">Buat yang pertama</a>
                @endboleh
            </x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
