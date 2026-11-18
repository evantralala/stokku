{{--
    Unggah file Excel (dibuka sebagai popup dari tombol Impor di daftar). Variabel dari AlurImporExcel::tampilUnggah:
      $judul, $teks ([judul halaman, keterangan singkat, tombol unduh]), $url (templat, pratinjau, simpan, kembali),
      $pilihanMode ([kunci => [label]], kosong bila tidak ada), $mode (bawaan).
    Penjelasan tiap kolom ada di lembar Petunjuk pada templat, tidak diulang di sini.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ $url['kembali'] }}">{{ $judul }}</a>
@endsection
@section('judul', $teks[0])

@section('content')
    <section class="panel impor-excel" data-popup-isi>
        <div class="kontrol-isi">
            @include('admin-utama.excel._form-unggah', [
                'ket' => $teks[1], 'templat' => $url['templat'], 'labelTemplat' => $teks[2], 'aksi' => $url['pratinjau'],
                'labelMode' => 'Cara Simpan', 'terima' => '.xlsx,.xls,.csv', 'teksFile' => 'Pilih File Excel atau CSV',
            ])
        </div>
    </section>
@endsection
