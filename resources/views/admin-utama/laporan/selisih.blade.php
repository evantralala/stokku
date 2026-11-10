{{-- Selisih kiriman dan opname. Variabel dari LaporanController@selisih: $kiriman, $opname, saringan. --}}
@extends('layouts.app')

@include('admin-utama.laporan._kepala')

@php
    $beli = fn ($r, $qty) => \App\Support\Angka::tampil((float) $qty / max((float) $r->isi, 0.000001)).' '.$r->satuan;
    $lewat = fn ($dasar, $selisih, $toleransi) => (float) $dasar > 0
        ? abs((float) $selisih) / (float) $dasar * 100 > (float) $toleransi
        : (float) $selisih != 0.0;
@endphp

@section('content')
    @include('admin-utama.laporan._saring')

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Selisih Kiriman</h2>
                <div class="sub">{{ $kiriman->count() }} barang diterima berbeda dari yang dikirim</div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th>Kiriman</th>
                        <th>Bahan</th>
                        <th class="ka">Dikirim</th>
                        <th class="ka">Diterima</th>
                        <th class="ka">Selisih</th>
                        <th>Catatan Penerima</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($kiriman as $r)
                        @php $selisih = (float) $r->terima - (float) $r->kirim; @endphp
                        <tr class="{{ $lewat($r->kirim, $selisih, $r->toleransi) ? 'baris-lewat' : '' }}">
                            <td data-label="">
                                <span class="nama-barang num">{{ $r->nomor }}</span>
                                <div class="satuan">{{ $r->lokasi }} · {{ \Illuminate\Support\Carbon::parse($r->waktu)->translatedFormat('j M Y') }}</div>
                            </td>
                            <td class="kartu-judul" data-label="Bahan"><span class="nama-barang">{{ $r->bahan }}</span></td>
                            <td class="ka num" data-label="Dikirim">{{ $beli($r, $r->kirim) }}</td>
                            <td class="ka num" data-label="Diterima">{{ $beli($r, $r->terima) }}</td>
                            <td class="ka num teks-kurang kartu-utama" data-label="Selisih">{{ $selisih > 0 ? '+' : '' }}{{ $beli($r, $selisih) }}</td>
                            <td data-label="">
                                {{ \App\Models\KirimanDetail::LABEL_KONDISI[$r->kondisi] ?? '' }}{{ $r->catatan ? ' · '.$r->catatan : '' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="kosong"><x-kosong gambar="selesai">Semua kiriman diterima sesuai.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Selisih Opname</h2>
                <div class="sub">{{ $opname->count() }} bahan berbeda dari stok sistem (opname disetujui)</div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th>Opname</th>
                        <th>Bahan</th>
                        <th class="ka">Stok Sistem</th>
                        <th class="ka">Hitung Fisik</th>
                        <th class="ka">Selisih</th>
                        <th>Catatan Petugas</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($opname as $r)
                        @php $selisih = (float) $r->fisik - (float) $r->sistem; @endphp
                        <tr class="{{ $lewat($r->sistem, $selisih, $r->toleransi) ? 'baris-lewat' : '' }}">
                            <td data-label="">
                                <span class="nama-barang num">{{ $r->nomor }}</span>
                                <div class="satuan">{{ $r->lokasi }} · {{ \Illuminate\Support\Carbon::parse($r->waktu)->translatedFormat('j M Y') }}</div>
                            </td>
                            <td class="kartu-judul" data-label="Bahan"><span class="nama-barang">{{ $r->bahan }}</span></td>
                            <td class="ka num" data-label="Stok sistem">{{ $beli($r, $r->sistem) }}</td>
                            <td class="ka num" data-label="Hitung fisik">{{ $beli($r, $r->fisik) }}</td>
                            <td class="ka num kartu-utama {{ $selisih < 0 ? 'teks-kurang' : 'teks-masuk' }}" data-label="Selisih">{{ $selisih > 0 ? '+' : '' }}{{ $beli($r, $selisih) }}</td>
                            <td @class(['hp-sembunyi' => ! $r->catatan]) data-label="">{{ $r->catatan ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="kosong"><x-kosong gambar="grafik">Tidak ada selisih opname pada periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="panduan">Baris berwarna = lewat toleransi.</p>
    </section>
@endsection
