{{--
    Pilih periode lalu unduh Excel (popup; tanpa JavaScript tampil sebagai halaman biasa).
    Variabel dari Concerns\UnduhPeriode: $judul, $rute (route unduh), $kembali (alamat halaman daftar), $parameter (query tetap), $periode.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ $kembali }}">Kembali</a>
@endsection
@section('judul', 'Unduh Excel')
@section('sub', $judul)

@section('content')
    <form class="panel" method="GET" action="{{ route($rute) }}" data-popup-isi>
        @foreach ($parameter as $kunci => $nilai)
            <input type="hidden" name="{{ $kunci }}" value="{{ $nilai }}">
        @endforeach

        <p class="intip-catatan satuan unduh-ket">Tekan periode cepat untuk langsung mengunduh, atau isi tanggal lalu tekan Unduh Excel.</p>

        <nav class="chip-saring chip-geser unduh-cepat" aria-label="Periode cepat">
            @foreach (\App\Support\Periode::pilihanCepat() as $label => [$dari, $sampai])
                <a class="chip" href="{{ route($rute, $parameter + ['dari' => $dari, 'sampai' => $sampai]) }}" download>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="form-grid">
            <x-form.teks name="dari" label="Dari" type="date" :value="$periode->dariTanggal()" :max="now()->toDateString()" />
            <x-form.teks name="sampai" label="Sampai" type="date" :value="$periode->sampaiTanggal()" :max="now()->toDateString()" />
        </div>

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ $kembali }}" data-popup-batal>Batal</a>
                <button type="submit" class="btn btn-merah">
                    <x-ikon nama="masuk" />
                    Unduh Excel
                </button>
            </div>
        </div>
    </form>
@endsection
