{{-- Daftar menu. Variabel dari MenuController. --}}
@extends('layouts.app')

@section('judul', 'Menu')
@section('sub', $jumlahAktif.' aktif, '.$jumlahNonaktif.' tidak aktif')

@section('content')
    <x-master.ringkas label="menu" ikon="mangkuk" :aktif="$jumlahAktif" :nonaktif="$jumlahNonaktif">
        <x-kartu-angka ikon="peringatan" label="Belum Ada Resep" :angka="$tanpaResep" :nada="$tanpaResep > 0 ? 'perhatian' : 'baik'"
                       ket="menu aktif tanpa resep" :tautan="route($rute, ['resep' => 'belum'])" />
    </x-master.ringkas>

    @if ($tanpaResep > 0 && ! $filter['resep'])
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>
                {{ $tanpaResep }} menu aktif belum punya resep, jadi penjualannya tidak memotong stok.
                <a class="link" href="{{ route($rute, ['resep' => 'belum']) }}">Lihat</a>
            </p>
        </div>
    @endif

    <div class="baris-excel">
        <p>Resep lewat Excel: satu baris per bahan. Menu yang belum ada ikut dibuat.</p>
        <div class="baris-excel-aksi">
            <x-master.tombol-excel master="resep" label="resep" />
        </div>
    </div>

    <section class="panel">
        <div class="panel-head">
            <x-master.alat :rute="$rute" :filter="$filter" placeholder="Cari nama menu">
                <label class="tersembunyi" for="filter-kategori">Kategori Menu</label>
                <select class="isian" id="filter-kategori" name="kategori" data-kirim-otomatis>
                    <option value="">Semua Kategori</option>
                    @foreach ($pilihanKategori as $id => $nama)
                        <option value="{{ $id }}" @selected($filter['kategori'] === $id)>{{ $nama }}</option>
                    @endforeach
                </select>

                <label class="tersembunyi" for="filter-resep">Resep</label>
                <select class="isian" id="filter-resep" name="resep" data-kirim-otomatis>
                    <option value="">Semua Resep</option>
                    <option value="belum" @selected($filter['resep'] === 'belum')>Belum Ada Resep</option>
                </select>

                <x-slot:aksi>
                    <x-tombol-riwayat jenis="menu" />
                    <x-master.tombol-excel master="menu" />
                    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah') }}" data-popup="panjang">
                        <x-ikon nama="plus" />
                        Tambah Menu
                    </a>
                </x-slot:aksi>
            </x-master.alat>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th class="kolom-no">No</th>
                        <th>Menu</th>
                        <th>Kategori</th>
                        <th>Resep</th>
                        <th class="tg">Status</th>
                        <th class="ka"><span class="tersembunyi">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $menu)
                        <tr>
                            <td class="kolom-no num hp-sembunyi" data-label="">{{ $daftar->firstItem() + $loop->index }}</td>
                            <td class="kartu-judul" data-label="Menu">
                                <div class="dengan-foto">
                                    <x-foto :src="$menu->urlFoto()" :nama="$menu->nama" />
                                    <div class="dengan-foto-teks">
                                        <a class="nama-barang tautan-baris" href="{{ route($rute.'.ubah', $menu) }}" data-popup="panjang">{{ $menu->nama }}</a>
                                    </div>
                                </div>
                            </td>
                            <td data-label="">{{ $menu->kategoriMenu->nama }}</td>
                            <td data-label="">
                                <a class="di-atas-tautan" href="{{ route($rute.'.resep', $menu) }}">
                                    @if ($menu->versi_berlaku)
                                        <span class="badge b-aman">Resep · {{ $menu->versi_berlaku->translatedFormat('j M') }}</span>
                                    @else
                                        <span class="badge b-awas">Belum Ada Resep</span>
                                    @endif
                                </a>
                                @if ($menu->ada_jadwal)
                                    <span class="badge b-jalan">Ada Jadwal Baru</span>
                                @endif
                            </td>
                            <td @class(['tg', 'hp-sembunyi' => $menu->aktif]) data-label=""><x-master.status :aktif="$menu->aktif" /></td>
                            <td class="ka kartu-aksi kartu-aksi-hp">
                                <div class="aksi-baris">
                                    <x-master.tombol-ubah :href="route($rute.'.resep', $menu)" :nama="$menu->nama" :popup="false" ikon="mangkuk" teks="Resep" />
                                    <x-master.tombol-ubah :href="route($rute.'.ubah', $menu)" :nama="$menu->nama" popup="panjang" />
                                    <x-master.tombol-hapus :action="route($rute.'.hapus', $menu)" :nama="$menu->nama" label="menu" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-master.kosong popup="panjang" :kolom="6" :ada-data="$jumlahAktif + $jumlahNonaktif > 0" :rute="$rute" label="menu" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
