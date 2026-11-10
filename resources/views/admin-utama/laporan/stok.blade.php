{{-- Rekap stok satu lokasi, dikelompokkan per kategori bahan. Variabel dari LaporanController@stok: $baris, $kolom, $lokasi, $periode, saringan. --}}
@extends('layouts.app')

@include('admin-utama.laporan._kepala')

@php
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty));
@endphp

@section('content')
    @include('admin-utama.laporan._saring')

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>{{ $lokasi?->nama ?? 'Belum Ada Lokasi' }}</h2>
                <div class="sub">Satuan bahan · masuk +, keluar −</div>
            </div>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-laporan tabel-ringkas">
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="ka">Awal</th>
                        @foreach ($kolom as $judulKolom)
                            <th class="ka">{{ $judulKolom }}</th>
                        @endforeach
                        <th class="ka">Akhir</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($baris as $r)
                        @php $b = $r['bahan']; @endphp
                        @if ($loop->first || $baris[$loop->index - 1]['bahan']->kategori_id !== $b->kategori_id)
                            <tr class="baris-kategori">
                                <th colspan="{{ count($kolom) + 3 }}" scope="colgroup">
                                    {{ $b->kategori->nama }}
                                    <span class="satuan">{{ $baris->where('bahan.kategori_id', $b->kategori_id)->count() }} bahan</span>
                                </th>
                            </tr>
                        @endif
                        <tr>
                            <td class="kartu-judul" data-label="Bahan">
                                <span class="nama-barang">{{ $b->nama }}</span>
                            </td>
                            <td class="ka num" data-label="Awal">{{ $keBeli($b, $r['awal']) }}</td>
                            @foreach ($kolom as $jenisMutasi => $judulKolom)
                                @php $nilai = $r['jenis'][$jenisMutasi]; @endphp
                                <td @class(['ka num', 'teks-kurang' => $nilai < 0, 'teks-masuk' => $nilai > 0, 'hp-sembunyi' => $nilai == 0]) data-label="{{ $judulKolom }}">
                                    {{ $nilai == 0 ? '—' : ($nilai > 0 ? '+' : '').$keBeli($b, $nilai) }}
                                </td>
                            @endforeach
                            <td class="ka num kartu-utama {{ $r['akhir'] < 0 ? 'teks-kurang' : '' }}" data-label="Akhir"><strong>{{ $keBeli($b, $r['akhir']) }}</strong> <span class="satuan">{{ $b->satuanBeli->nama }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($kolom) + 3 }}" class="kosong"><x-kosong gambar="grafik">Tidak ada stok atau pergerakan di periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
