{{--
    Unggah file Impor Sekaligus (popup dari dashboard). $kolom: [lembar => [kolom => keterangan]],
    $pilihanMode: [kunci => [label]] untuk lembar Stok, $mode: bawaan.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route(auth()->user()->ruteDashboard()) }}">Dashboard</a>
@endsection
@section('judul', 'Impor Sekaligus')

@section('content')
    <section class="panel impor-excel" data-popup-isi>
        <div class="kontrol-isi">
            <ol class="impor-urutan">
                <li>
                    Unduh templat. Isi lembar yang diperlukan saja, lembar lain boleh dibiarkan kosong atau dihapus. Semua tabel juga boleh ditumpuk dalam satu lembar.
                    <a class="btn btn-sm" href="{{ route('admin-utama.impor-sekaligus.templat') }}" download>
                        <x-ikon nama="masuk" width="18" height="18" />
                        Unduh Templat
                    </a>
                </li>
                <li>Pilih file yang sudah diisi, lalu cek sebelum disimpan.</li>
            </ol>

            <form class="impor-unggah" method="POST" action="{{ route('admin-utama.impor-sekaligus.pratinjau') }}" enctype="multipart/form-data" novalidate>
                @csrf
                <fieldset class="impor-mode">
                    <legend class="label-isian">Cara Simpan Stok</legend>
                    @foreach ($pilihanMode as $kunci => [$label])
                        <label class="impor-mode-pilih">
                            <input type="radio" name="mode" value="{{ $kunci }}" @checked(old('mode', $mode) === $kunci)>
                            <span class="nama-barang">{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <label class="impor-file @error('file') galat @enderror" for="file-excel">
                    <x-ikon nama="keluar" />
                    <span class="impor-file-teks" data-nama-file>Pilih File Excel (.xlsx)</span>
                    <input class="tersembunyi" id="file-excel" type="file" name="file" required accept=".xlsx,.xls"
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

            @foreach ($kolom as $lembar => $daftar)
                <details class="impor-kolom">
                    <summary>Isi Lembar {{ $lembar }}</summary>
                    <dl>
                        @foreach ($daftar as $nama => $ket)
                            <div>
                                <dt>{{ \App\Support\GayaExcel::judulKolom($nama) }}</dt>
                                <dd>{{ $ket }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            @endforeach
        </div>
    </section>
@endsection
