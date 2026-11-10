{{--
    Unggah file Excel (dibuka sebagai popup dari tombol Impor di daftar). Variabel dari AlurImporExcel::tampilUnggah:
      $judul, $teks ([judul halaman, langkah unduh, tombol unduh]), $kolom ([kunci kolom => tampilan]),
      $url (templat, pratinjau, simpan, kembali), $pilihanMode ([kunci => [label]], kosong bila tidak ada), $mode (bawaan).
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ $url['kembali'] }}">{{ $judul }}</a>
@endsection
@section('judul', $teks[0])

@section('content')
    <section class="panel impor-excel" data-popup-isi>
        <div class="kontrol-isi">
            <ol class="impor-urutan">
                <li>
                    {{ $teks[1] }}
                    <a class="btn btn-sm" href="{{ $url['templat'] }}" download>
                        <x-ikon nama="masuk" width="18" height="18" />
                        {{ $teks[2] }}
                    </a>
                </li>
                <li>Pilih file yang sudah diisi, lalu cek sebelum disimpan.</li>
            </ol>

            <form class="impor-unggah" method="POST" action="{{ $url['pratinjau'] }}" enctype="multipart/form-data" novalidate>
                @csrf
                @if ($pilihanMode !== [])
                    <fieldset class="impor-mode">
                        <legend class="label-isian">Cara Simpan</legend>
                        @foreach ($pilihanMode as $kunci => [$label])
                            <label class="impor-mode-pilih">
                                <input type="radio" name="mode" value="{{ $kunci }}" @checked(old('mode', $mode) === $kunci)>
                                <span class="nama-barang">{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif
                <label class="impor-file @error('file') galat @enderror" for="file-excel">
                    <x-ikon nama="keluar" />
                    <span class="impor-file-teks" data-nama-file>Pilih File Excel (.xlsx) atau CSV</span>
                    <input class="tersembunyi" id="file-excel" type="file" name="file" required accept=".xlsx,.xls,.csv"
                           @error('file') aria-invalid="true" aria-describedby="file-excel-galat" @enderror>
                </label>
                @error('file')
                    <span class="pesan-galat" id="file-excel-galat">{{ $message }}</span>
                @enderror
                <button type="submit" class="btn btn-gelap">
                    <x-ikon nama="ceklis" />
                    Cek Isi File
                </button>
            </form>

            <details class="impor-kolom">
                <summary>Isi Kolom</summary>
                <dl>
                    @foreach ($kolom as $nama => $t)
                        <div>
                            <dt>{{ \App\Support\GayaExcel::judulKolom($nama) }}</dt>
                            <dd>{{ $t['ket'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </details>
        </div>
    </section>
@endsection
