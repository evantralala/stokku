{{-- Daftar pengguna. Variabel dari PenggunaController. --}}
@extends('layouts.app')

@section('judul', 'Pengguna')
@section('sub', $jumlahAktif.' aktif, '.$jumlahNonaktif.' tidak aktif')

@section('content')
    <x-master.ringkas label="pengguna" ikon="pengguna" :aktif="$jumlahAktif" :nonaktif="$jumlahNonaktif" />

    <section class="panel">
        <div class="panel-head">
            <x-master.alat :rute="$rute" :filter="$filter" placeholder="Cari nama atau email">
                <label class="tersembunyi" for="filter-peran">Peran</label>
                <select class="isian" id="filter-peran" name="peran" data-kirim-otomatis>
                    <option value="">Semua Peran</option>
                    @foreach ($pilihanPeran as $nilai => $teks)
                        <option value="{{ $nilai }}" @selected($filter['peran'] === $nilai)>{{ $teks }}</option>
                    @endforeach
                </select>

                <x-slot:aksi>
                    <x-tombol-riwayat jenis="pengguna" />
                    <x-master.tombol-excel master="pengguna" />
                    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah') }}" data-popup="panjang">
                        <x-ikon nama="plus" />
                        Tambah Pengguna
                    </a>
                </x-slot:aksi>
            </x-master.alat>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th class="kolom-no">No</th>
                        <th>Pengguna</th>
                        <th>Peran</th>
                        <th>Lokasi</th>
                        <th class="tg">Status</th>
                        <th class="ka"><span class="tersembunyi">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $u)
                        @php $bisaKelola = $u->bisaDikelolaOleh($pengguna); @endphp
                        <tr>
                            <td class="kolom-no num hp-sembunyi" data-label="">{{ $daftar->firstItem() + $loop->index }}</td>
                            <td class="kartu-judul" data-label="Pengguna">
                                @if ($bisaKelola)
                                    <a class="nama-barang tautan-baris" href="{{ route($rute.'.ubah', $u) }}" data-popup="panjang">{{ $u->nama }}</a>
                                @else
                                    <span class="nama-barang">{{ $u->nama }}</span>
                                @endif
                                @if ($u->is($pengguna))
                                    <span class="badge b-jalan">Anda</span>
                                @endif
                                <div class="satuan">{{ $u->email }}</div>
                            </td>
                            <td class="kartu-utama" data-label="Peran">
                                <span class="badge {{ $u->isAdminUtama() ? 'b-pusat' : 'b-draf' }}">{{ $u->label_role }}</span>
                            </td>
                            <td data-label="">{{ $u->cabang?->nama ?? 'Semua Lokasi' }}</td>
                            <td @class(['tg', 'hp-sembunyi' => $u->aktif]) data-label=""><x-master.status :aktif="$u->aktif" /></td>
                            <td class="ka kartu-aksi kartu-aksi-hp">
                                <div class="aksi-baris">
                                    @if ($bisaKelola)
                                        <x-master.tombol-ubah :href="route($rute.'.ubah', $u)" :nama="$u->nama" popup="panjang" />
                                    @endif
                                    @unless ($u->is(auth()->user()) || ! $bisaKelola)
                                        <x-master.tombol-hapus :action="route($rute.'.hapus', $u)" :nama="$u->nama" label="pengguna" />
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-master.kosong popup="panjang" :kolom="6" :ada-data="$jumlahAktif + $jumlahNonaktif > 0" :rute="$rute" label="pengguna" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
