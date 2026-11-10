{{-- Barang rusak per bahan. Variabel dari LaporanController@rusak: $per_bahan, saringan. --}}
@extends('layouts.app')

@include('admin-utama.laporan._kepala')

@section('content')
    @include('admin-utama.laporan._saring')

    <section class="panel">
        <div class="panel-head"><h2>Per Bahan</h2></div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="ka">Berapa Kali</th>
                        <th class="ka">Jumlah Terbuang</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($per_bahan as $r)
                        <tr>
                            <td class="kartu-judul" data-label="Bahan"><span class="nama-barang">{{ $r->bahan }}</span></td>
                            <td class="ka num" data-label="Berapa kali">{{ $r->kali }}×</td>
                            <td class="ka num kartu-utama" data-label="Jumlah terbuang">
                                {{ \App\Support\Angka::tampil((float) $r->qty / max((float) $r->isi, 0.000001)) }} {{ $r->satuan }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="kosong"><x-kosong gambar="grafik">Tidak ada barang rusak yang disetujui pada periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
