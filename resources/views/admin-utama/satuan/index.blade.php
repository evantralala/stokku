{{-- Daftar satuan. Variabel dari SatuanController. --}}
@extends('layouts.app')

@section('judul', 'Satuan')
@section('sub', $jumlahAktif.' aktif, '.$jumlahNonaktif.' tidak aktif')

@section('content')
    <x-master.ringkas label="satuan" ikon="timbangan" :aktif="$jumlahAktif" :nonaktif="$jumlahNonaktif" />

    <section class="panel">
        <div class="panel-head">
            <x-master.alat :rute="$rute" :filter="$filter" placeholder="Cari satuan">
                <x-slot:aksi>
                    <x-tombol-riwayat jenis="satuan" />
                    <x-master.tombol-excel master="satuan" />
                    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah') }}" data-popup>
                        <x-ikon nama="plus" />
                        Tambah Satuan
                    </a>
                </x-slot:aksi>
            </x-master.alat>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th class="kolom-no">No</th>
                        <th>Satuan</th>
                        <th class="ka">Dipakai di Resep</th>
                        <th class="ka">Dipakai sebagai Satuan Bahan</th>
                        <th class="tg">Status</th>
                        <th class="ka"><span class="tersembunyi">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $satuan)
                        <tr>
                            <td class="kolom-no num hp-sembunyi" data-label="">{{ $daftar->firstItem() + $loop->index }}</td>
                            <td class="kartu-judul" data-label="Satuan"><a class="nama-barang tautan-baris" href="{{ route($rute.'.ubah', $satuan) }}" data-popup>{{ $satuan->nama }}</a></td>
                            <td @class(['ka num', 'hp-sembunyi' => ! $satuan->jumlah_dasar]) data-label="Di resep">{{ $satuan->jumlah_dasar }} bahan</td>
                            <td @class(['ka num', 'hp-sembunyi' => ! $satuan->jumlah_beli]) data-label="Satuan bahan">{{ $satuan->jumlah_beli }} bahan</td>
                            <td @class(['tg', 'hp-sembunyi' => $satuan->aktif]) data-label=""><x-master.status :aktif="$satuan->aktif" /></td>
                            <td class="ka kartu-aksi kartu-aksi-hp">
                                <div class="aksi-baris">
                                    <x-master.tombol-ubah :href="route($rute.'.ubah', $satuan)" :nama="$satuan->nama" />
                                    <x-master.tombol-hapus :action="route($rute.'.hapus', $satuan)" :nama="$satuan->nama" label="satuan" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-master.kosong :kolom="6" :ada-data="$jumlahAktif + $jumlahNonaktif > 0" :rute="$rute" label="satuan" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection