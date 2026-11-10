{{--
    Daftar penjualan harian cabang. Variabel dari PenjualanController@index: $daftar, $lokasi,
    $sudahHariIni (catatan hari operasional), $hariOperasional (sebelum jam ganti hari = kemarin),
    $hariDiarsipkan (penjualan hari operasional sudah ikut tutup buku).
    Impor Excel membuka popup unggah (PenjualanExcelController); hasilnya sama dengan Catat Hari Ini.
--}}
@extends('layouts.app')

@section('segar', 'ya')

@section('judul', 'Penjualan')
@section('sub', $lokasi->nama)

@php
    $labelHari = $hariOperasional->isToday() ? 'Hari Ini' : $hariOperasional->translatedFormat('j M');
    $diarsipkan = ! $sudahHariIni && $hariDiarsipkan;
@endphp

@section('aksi')
    <x-tombol-unduh :href="route('cabang.penjualan.unduh')" periode />
    <a class="btn tombol-ikon-hp" href="{{ route('cabang.penjualan.impor') }}" data-popup aria-label="Impor penjualan dari Excel">
        <x-ikon nama="keluar" />
        <span>Impor Excel</span>
    </a>
    @unless ($diarsipkan)
        <a class="btn btn-merah" href="{{ $sudahHariIni ? route('cabang.penjualan.lihat', $sudahHariIni) : route('cabang.penjualan.catat') }}"
           @unless ($sudahHariIni) data-popup="panjang" @endunless>
            <x-ikon nama="{{ $sudahHariIni ? 'ceklis' : 'plus' }}" />
            {{ $sudahHariIni ? 'Lihat '.$labelHari : 'Catat '.$labelHari }}
        </a>
    @endunless
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="mangkuk" :label="$labelHari" :angka="$sudahHariIni ? 'Sudah' : ($diarsipkan ? 'Arsip' : 'Belum')" :nada="$sudahHariIni || $diarsipkan ? 'baik' : 'perhatian'"
                       :ket="$sudahHariIni ? 'penjualan sudah dicatat' : ($diarsipkan ? 'ikut tutup buku' : 'catat setelah tutup')" />
        <x-kartu-angka ikon="kalender" label="Catatan Penjualan" :angka="$daftar->total()" :ket="'hari tercatat di '.$lokasi->nama" />
    </div>

    @unless ($sudahHariIni || $diarsipkan)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Penjualan {{ $hariOperasional->isToday() ? 'hari ini' : $hariOperasional->translatedFormat('l, j M') }} belum dicatat. Catat setelah tutup.</p>
        </div>
    @endunless

    <section class="panel">
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas tabel-dokumen">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Pencatat</th>
                        <th class="ka">Menu</th>
                        <th class="ka">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $p)
                        <tr>
                            <td class="kartu-judul" data-label="Tanggal">
                                <a class="tautan-baris nama-barang" href="{{ route('cabang.penjualan.lihat', $p) }}">{{ $p->tanggal->translatedFormat('l, j M') }}</a>
                            </td>
                            <td data-label="">{{ $p->namaPencatat() }}</td>
                            <td class="ka num" data-label="">{{ $p->detail_count }} menu</td>
                            <td class="ka num kartu-utama" data-label="">{{ \App\Support\Angka::tampil($p->total_porsi ?? 0) }} porsi</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="kosong"><x-kosong gambar="dokumen">Belum ada penjualan yang dicatat.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
