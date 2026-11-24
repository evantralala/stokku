{{--
    Popup tandai hari libur cabang. Variabel dari LiburController@create:
      $rute (cabang.libur / pusat.libur / admin-utama.libur), $lokasi (cabang sendiri untuk Admin Cabang, selain itu null),
      $pilihanTanggal ([Y-m-d => label], Admin Cabang), $pilihanCabang ([id => nama]), $cabangId, $tanggal, $hariIni, $batasAwal.
--}}
@extends('layouts.app')

@php
    $kembali = match ($rute) {
        'cabang.libur' => route('cabang.penjualan'),
        'pusat.libur'  => route('pusat.libur'),
        default        => route('admin-utama.penjualan'),
    };
    $terpilih = old('tanggal', array_key_exists($tanggal, $pilihanTanggal) ? $tanggal : array_key_first($pilihanTanggal));
@endphp

@section('crumb')
    <a class="link" href="{{ $kembali }}">{{ $rute === 'pusat.libur' ? 'Libur Cabang' : 'Penjualan' }}</a>
@endsection
@section('judul', 'Tandai Libur')
@section('sub', $lokasi?->nama ?? 'Tanpa penjualan, tidak diingatkan lagi')

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="pendek">
        <form class="panel" method="POST" action="{{ route($rute.'.simpan') }}" novalidate
              data-konfirmasi="Tanggal ini dianggap tutup dan tidak diingatkan lagi. Bila ternyata buka, cukup catat penjualannya."
              data-konfirmasi-judul="Tandai Libur?"
              data-konfirmasi-tombol="Ya, Libur">
            @csrf

            @if ($lokasi)
                @if ($pilihanTanggal === [])
                    <x-kosong gambar="dokumen" kecil>Semua hari sudah dicatat.</x-kosong>
                @else
                    <div class="form-grid">
                    <fieldset class="impor-mode impor-tanggal">
                        <legend class="label-isian">Tanggal</legend>
                        <div class="pilih-segmen pilih-tanggal">
                            @foreach ($pilihanTanggal as $kunci => $label)
                                <label>
                                    <input type="radio" name="tanggal" value="{{ $kunci }}" @checked($terpilih === $kunci)>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('tanggal')
                            <span class="pesan-galat">{{ $message }}</span>
                        @enderror
                    </fieldset>
                    </div>
                @endif
            @else
                <div class="form-grid">
                    <x-form.pilihan name="cabang_id" label="Cabang" :pilihan="$pilihanCabang" :value="$cabangId" kosong="Pilih cabang" wajib />
                    <x-form.teks name="tanggal" label="Tanggal" type="date" wajib :value="$tanggal" :min="$batasAwal" :max="$hariIni" />
                </div>
            @endif

            @unless ($lokasi && $pilihanTanggal === [])
                <x-form.simpan :batal="$kembali" label="Tandai Libur" />
            @endunless
        </form>
    </div>
@endsection
