{{--
    Pilih kategori untuk lembar stock opname PDF (popup). Variabel dari PantauStok::lembarOpname():
      $lokasi, $rute, $pilihanKategori, $parameterLokasi.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', 'Lembar Opname')
@section('sub', $lokasi->nama)

@section('content')
    <form class="panel" method="GET" action="{{ route($rute.'.lembar-opname.unduh') }}" data-popup-isi>
        @foreach ($parameterLokasi as $kunci => $nilai)
            <input type="hidden" name="{{ $kunci }}" value="{{ $nilai }}">
        @endforeach

        <p class="intip-catatan satuan">PDF berisi nama bahan, stok sistem, dan satuan per kategori, dengan kolom kosong untuk hasil hitung fisik.</p>

        <div class="form-grid">
            <x-form.pilihan name="kategori" label="Kategori" :pilihan="$pilihanKategori" kosong="Semua Kategori" lebar />
        </div>

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ route($rute, $parameterLokasi) }}" data-popup-batal>Batal</a>
                <button type="submit" class="btn btn-merah">
                    <x-ikon nama="masuk" />
                    Unduh PDF
                </button>
            </div>
        </div>
    </form>
@endsection
