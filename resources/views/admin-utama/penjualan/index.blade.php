{{--
    Catatan penjualan harian semua cabang (Admin Utama). Variabel dari AdminUtama\PenjualanController@index:
      $daftar (paginasi), $pilihanLokasi ([id => nama cabang]), $lokasi (id cabang yang disaring, atau null).
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('admin-utama.laporan.penjualan') }}">Laporan Penjualan</a>
@endsection
@section('judul', 'Catatan Penjualan')
@section('sub', 'Buka catatan untuk melihat atau membetulkan porsi')

@section('content')
    @if ($pilihanLokasi->count() > 1)
        <nav class="chip-saring chip-geser" aria-label="Saring cabang">
            <a class="chip" href="{{ route('admin-utama.penjualan') }}"{!! $lokasi === null ? ' aria-current="true"' : '' !!}>Semua Cabang</a>
            @foreach ($pilihanLokasi as $id => $nama)
                <a class="chip" href="{{ route('admin-utama.penjualan', ['lokasi' => $id]) }}"{!! $lokasi === $id ? ' aria-current="true"' : '' !!}>{{ $nama }}</a>
            @endforeach
        </nav>
    @endif

    <section class="panel">
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas tabel-dokumen">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Pencatat</th>
                        <th class="ka">Menu</th>
                        <th class="ka">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $p)
                        <tr>
                            <td class="kartu-judul" data-label="Tanggal">
                                <a class="tautan-baris nama-barang" href="{{ route('admin-utama.penjualan.lihat', $p) }}">{{ $p->tanggal->translatedFormat('l, j M Y') }}</a>
                            </td>
                            <td data-label="">{{ $p->cabang->nama }}</td>
                            <td data-label="">{{ $p->namaPencatat() }}</td>
                            <td class="ka num" data-label="">{{ $p->detail_count }} menu</td>
                            <td class="ka num kartu-utama" data-label="">{{ \App\Support\Angka::tampil($p->total_porsi ?? 0) }} porsi</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="kosong"><x-kosong gambar="dokumen">Belum ada penjualan yang dicatat.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
