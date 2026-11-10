{{-- Daftar bahan baku. Variabel dari BahanBakuController. --}}
@extends('layouts.app')

@section('judul', 'Bahan Baku')
@section('sub', $jumlahAktif.' aktif, '.$jumlahNonaktif.' tidak aktif')

@section('content')
    <x-master.ringkas label="bahan baku" ikon="kotak" :aktif="$jumlahAktif" :nonaktif="$jumlahNonaktif">
        <x-kartu-angka warna="jingga" ikon="label" label="Kategori Bahan" :angka="$pilihanKategori->count()" ket="kelompok bahan" :tautan="route('admin-utama.kategori')" />
    </x-master.ringkas>

    <section class="panel">
        <div class="panel-head">
            <x-master.alat :rute="$rute" :filter="$filter" placeholder="Cari nama bahan">
                <label class="tersembunyi" for="filter-kategori">Kategori</label>
                <select class="isian" id="filter-kategori" name="kategori" data-kirim-otomatis>
                    <option value="">Semua Kategori</option>
                    @foreach ($pilihanKategori as $id => $nama)
                        <option value="{{ $id }}" @selected($filter['kategori'] === $id)>{{ $nama }}</option>
                    @endforeach
                </select>

                <x-slot:aksi>
                    <x-tombol-riwayat jenis="bahan-baku" />
                    <x-master.tombol-excel master="bahan-baku" />
                    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah') }}" data-popup="panjang">
                        <x-ikon nama="plus" />
                        Tambah Bahan
                    </a>
                </x-slot:aksi>
            </x-master.alat>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th class="kolom-no">No</th>
                        <th>Bahan</th>
                        <th>Kategori</th>
                        <th>Satuan</th>
                        <th class="ka">Toleransi</th>
                        <th class="tg">Status</th>
                        <th class="ka"><span class="tersembunyi">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $bahan)
                        <tr>
                            <td class="kolom-no num hp-sembunyi" data-label="">{{ $daftar->firstItem() + $loop->index }}</td>
                            <td class="kartu-judul" data-label="Bahan">
                                <div class="dengan-foto">
                                    <x-foto :src="$bahan->urlFoto()" :nama="$bahan->nama" />
                                    <div class="dengan-foto-teks">
                                        <a class="nama-barang tautan-baris" href="{{ route($rute.'.ubah', $bahan) }}" data-popup="panjang">{{ $bahan->nama }}</a>
                                        <div class="satuan hp-saja-teks">{{ $bahan->kategori->nama }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="hp-sembunyi" data-label="Kategori">{{ $bahan->kategori->nama }}</td>
                            <td class="num" data-label="">
                                @if ((int) $bahan->satuan_beli_id === (int) $bahan->satuan_dasar_id)
                                    {{ $bahan->satuanDasar->nama }}
                                @elseif (\App\Support\SatuanTunggal::turunan($bahan->satuanBeli->nama) === [$bahan->satuanDasar->nama, (float) $bahan->isi_per_satuan_beli])
                                    {{ $bahan->satuanBeli->nama }}
                                @else
                                    1 {{ $bahan->satuanBeli->nama }} = {{ \App\Support\Angka::tampil($bahan->isi_per_satuan_beli) }} {{ $bahan->satuanDasar->nama }}
                                @endif
                            </td>
                            <td class="ka num kartu-utama" data-label="Toleransi">{{ \App\Support\Angka::tampil($bahan->toleransi_persen, 2) }}%</td>
                            <td @class(['tg', 'hp-sembunyi' => $bahan->aktif]) data-label=""><x-master.status :aktif="$bahan->aktif" /></td>
                            <td class="ka kartu-aksi kartu-aksi-hp" data-label="">
                                <div class="aksi-baris">
                                    <x-master.tombol-ubah :href="route($rute.'.ubah', $bahan)" :nama="$bahan->nama" popup="panjang" />
                                    <x-master.tombol-hapus :action="route($rute.'.hapus', $bahan)" :nama="$bahan->nama" label="bahan baku" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-master.kosong popup="panjang" :kolom="7" :ada-data="$jumlahAktif + $jumlahNonaktif > 0" :rute="$rute" label="bahan baku" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
