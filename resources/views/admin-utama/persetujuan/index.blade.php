{{--
    Antrean persetujuan Admin Utama. Variabel dari PersetujuanController@index:
      $antrean (baris AntreanPersetujuanService), $jumlahWajar, $jenis, $pilihanJenis.
    Dokumen wajar bisa dicentang lalu disetujui sekaligus lewat bilah bawah.
--}}
@extends('layouts.app')

@section('judul', 'Persetujuan')
@section('segar', 'ya')
@section('sub', $antrean->isEmpty() ? 'Tidak ada yang menunggu' : $antrean->count().' dokumen menunggu')

@section('alat')
    <nav class="chip-saring chip-geser" aria-label="Jenis dokumen">
        <a class="chip" href="{{ route('admin-utama.persetujuan') }}" @if ($jenis === '') aria-current="true" @endif>Semua</a>
        @foreach ($pilihanJenis as $nilai => $teks)
            <a class="chip" href="{{ route('admin-utama.persetujuan', ['jenis' => $nilai]) }}" @if ($jenis === $nilai) aria-current="true" @endif>{{ $teks }}</a>
        @endforeach
    </nav>
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="ceklis" label="Menunggu Keputusan" :angka="$antrean->count()" :nada="$antrean->isNotEmpty() ? 'perhatian' : 'baik'" ket="semua jenis dokumen" />
        <x-kartu-angka ikon="perisai" label="Wajar" :angka="$jumlahWajar" nada="baik" ket="bisa disetujui sekaligus" />
    </div>

    <section class="panel">
        @if ($antrean->isEmpty())
            <x-kosong gambar="selesai" ket="Dokumen baru dari Gudang Pusat dan cabang akan muncul di sini.">Semua sudah diputuskan.</x-kosong>
        @else
            @include('admin-utama.persetujuan._tabel-antrean', ['daftar' => $antrean, 'pilih' => $jumlahWajar > 0 ? 'pilih-setujui' : null])
            @if ($jumlahWajar > 0)
            <x-pilih.bilah id="pilih-setujui" :action="route('admin-utama.persetujuan.wajar.setujui')" tombol="Setujui yang Dicentang" hijau
                           konfirmasi="Semua dokumen wajar yang dicentang disetujui sekaligus dan tidak bisa diubah lagi."
                           judul-konfirmasi="Setujui yang Dicentang?" tombol-konfirmasi="Ya, Setujui" />
            @endif
        @endif

    </section>
@endsection
