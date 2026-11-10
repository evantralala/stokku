{{--
    Penjualan per menu (dikelompokkan per kategori menu, dengan subtotal) dan pemakaian bahan (dikelompokkan per kategori bahan).
    Variabel dari LaporanController@penjualan: $menu, $bahan, saringan.
--}}
@extends('layouts.app')

@include('admin-utama.laporan._kepala')

@section('aksi')
    <a class="btn tombol-ikon-hp" href="{{ route('admin-utama.penjualan') }}" aria-label="Catatan penjualan per hari">
        <x-ikon nama="kalender" />
        <span>Catatan Penjualan</span>
    </a>
    <x-tombol-riwayat jenis="penjualan" label="Riwayat Impor" />
    <x-master.tombol-excel master="penjualan" label="penjualan" />
@endsection

@php
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    $totalPorsi = $menu->sum('porsi');
    $kolomMenu = 2;
@endphp

@section('content')
    @include('admin-utama.laporan._saring')

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Porsi per Menu</h2>
                <div class="sub">Total {{ \App\Support\Angka::tampil($totalPorsi) }} porsi</div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th>Menu</th>
                        <th class="ka">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($menu->groupBy('kategori') as $namaKategori => $daftar)
                        @php $porsiKategori = $daftar->sum('porsi'); @endphp
                        <tr class="baris-kategori">
                            <th colspan="{{ $kolomMenu }}" scope="colgroup">
                                {{ $namaKategori }}
                                <span class="satuan">
                                    {{ \App\Support\Angka::tampil($porsiKategori) }} porsi
                                    · {{ \App\Support\Angka::tampil($totalPorsi > 0 ? $porsiKategori / $totalPorsi * 100 : 0, 1) }}%
                                </span>
                            </th>
                        </tr>
                        @foreach ($daftar as $m)
                            <tr>
                                <td class="kartu-judul" data-label="Menu"><span class="nama-barang">{{ $m->nama }}</span></td>
                                <td class="ka num kartu-utama" data-label="Porsi">{{ \App\Support\Angka::tampil($m->porsi) }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="{{ $kolomMenu }}" class="kosong"><x-kosong gambar="grafik">Tidak ada penjualan pada periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Pemakaian Bahan</h2>
                <div class="sub">
                    <strong>Hilang</strong> = kurang saat opname. Persentase tinggi patut dicek.
                </div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="ka">Pemakaian Resep</th>
                        <th class="ka">Rusak</th>
                        <th class="ka">Hilang (Opname)</th>
                        <th class="ka">Lebih (Opname)</th>
                        <th class="ka">Hilang / Pemakaian</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bahan->groupBy(fn ($r) => $r['bahan']->kategori->nama) as $namaKategori => $daftar)
                        <tr class="baris-kategori">
                            <th colspan="6" scope="colgroup">
                                {{ $namaKategori }}
                                <span class="satuan">{{ $daftar->count() }} bahan</span>
                            </th>
                        </tr>
                        @foreach ($daftar as $r)
                            @php $b = $r['bahan']; @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Bahan"><span class="nama-barang">{{ $b->nama }}</span></td>
                                <td @class(['ka num kartu-utama']) data-label="Pemakaian resep">{{ $r['pemakaian'] > 0 ? $keBeli($b, $r['pemakaian']) : '—' }}</td>
                                <td @class(['ka num', 'hp-sembunyi' => $r['rusak'] <= 0]) data-label="Rusak">{{ $r['rusak'] > 0 ? $keBeli($b, $r['rusak']) : '—' }}</td>
                                <td @class(['ka num', 'teks-kurang' => $r['hilang'] > 0, 'hp-sembunyi' => $r['hilang'] <= 0]) data-label="Hilang (opname)">{{ $r['hilang'] > 0 ? $keBeli($b, $r['hilang']) : '—' }}</td>
                                <td @class(['ka num', 'hp-sembunyi' => $r['lebih'] <= 0]) data-label="Lebih (opname)">{{ $r['lebih'] > 0 ? $keBeli($b, $r['lebih']) : '—' }}</td>
                                <td @class(['ka num', 'teks-kurang' => ($r['persen_hilang'] ?? 0) > $b->toleransi_persen, 'hp-sembunyi' => $r['persen_hilang'] === null]) data-label="Hilang / pemakaian">
                                    {{ $r['persen_hilang'] === null ? '—' : \App\Support\Angka::tampil($r['persen_hilang'], 1).'%' }}
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="6" class="kosong"><x-kosong gambar="grafik">Belum ada pemakaian, barang rusak, atau koreksi opname pada periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
