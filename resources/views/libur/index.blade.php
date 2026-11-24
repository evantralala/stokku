{{--
    Hari libur semua cabang (Admin Pusat). Variabel dari LiburController@index: $daftar (paginasi PenjualanLibur),
    $rute (pusat.libur), $bolehCatat (hak akses Libur Cabang: catat).
--}}
@extends('layouts.app')

@section('judul', 'Libur Cabang')
@section('sub', 'Hari tanpa penjualan yang tidak diingatkan')

@if ($bolehCatat)
    @section('aksi')
        <a class="btn btn-merah" href="{{ route($rute.'.catat') }}" data-popup>
            <x-ikon nama="plus" />
            Tandai Libur
        </a>
    @endsection
@endif

@section('content')
    <section class="panel">
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas tabel-dokumen">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Cabang</th>
                        <th>Pencatat</th>
                        <th class="ka">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $libur)
                        @include('libur._baris', ['libur' => $libur, 'tampilCabang' => true, 'kolomSisa' => 1, 'ruteHapus' => $bolehCatat ? $rute.'.hapus' : null])
                    @empty
                        <tr><td colspan="4" class="kosong"><x-kosong gambar="dokumen">Belum ada hari libur.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
