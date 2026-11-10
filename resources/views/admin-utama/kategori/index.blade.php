{{-- Daftar kategori bahan baku. Variabel dari KategoriController. --}}
@extends('layouts.app')

@section('judul', 'Kategori Bahan')
@section('sub', $jumlahAktif.' aktif, '.$jumlahNonaktif.' tidak aktif')

@section('content')
    <x-master.ringkas label="kategori bahan" ikon="label" :aktif="$jumlahAktif" :nonaktif="$jumlahNonaktif" />

    <section class="panel">
        <div class="panel-head">
            <x-master.alat :rute="$rute" :filter="$filter" placeholder="Cari kategori">
                <x-slot:aksi>
                    <x-tombol-riwayat jenis="kategori" />
                    <x-master.tombol-excel master="kategori" />
                    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah') }}" data-popup>
                        <x-ikon nama="plus" />
                        Tambah Kategori
                    </a>
                </x-slot:aksi>
            </x-master.alat>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th class="kolom-no">No</th>
                        <th class="ka">Urutan</th>
                        <th>Kategori</th>
                        <th class="ka">Jumlah Bahan</th>
                        <th class="tg">Status</th>
                        <th class="ka"><span class="tersembunyi">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $kategori)
                        <tr>
                            <td class="kolom-no num hp-sembunyi" data-label="">{{ $daftar->firstItem() + $loop->index }}</td>
                            <td class="ka num" data-label="Urutan ke-">{{ $kategori->urutan }}</td>
                            <td class="kartu-judul" data-label="Kategori"><a class="nama-barang tautan-baris" href="{{ route($rute.'.ubah', $kategori) }}" data-popup>{{ $kategori->nama }}</a></td>
                            <td class="ka num kartu-utama" data-label="Jumlah bahan">{{ $kategori->jumlah_bahan }}<span class="hp-saja-teks"> bahan</span></td>
                            <td @class(['tg', 'hp-sembunyi' => $kategori->aktif]) data-label=""><x-master.status :aktif="$kategori->aktif" /></td>
                            <td class="ka kartu-aksi kartu-aksi-hp">
                                <div class="aksi-baris">
                                    <x-master.tombol-ubah :href="route($rute.'.ubah', $kategori)" :nama="$kategori->nama" />
                                    <x-master.tombol-hapus :action="route($rute.'.hapus', $kategori)" :nama="$kategori->nama" label="kategori bahan" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-master.kosong :kolom="6" :ada-data="$jumlahAktif + $jumlahNonaktif > 0" :rute="$rute" label="kategori bahan" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection