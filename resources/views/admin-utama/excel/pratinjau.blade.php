{{--
    Pratinjau impor Excel sebelum disimpan. Variabel dari AlurImporExcel::tampilPratinjau:
      $layanan (ImporExcel), $url (templat, pratinjau, simpan, kembali),
      $baris (['baris', 'status', 'data', 'pesan', 'catatan', 'betul', 'tebakan', 'tunggu', 'ringkas'?], salah dan perlu dicek di atas),
      $hitung ([status => n]), $dibetulkan (baris siap yang isinya dibetulkan otomatis), $kunci, $namaFile,
      $tebakan (pertanyaan "Maksudnya …?" unik: kunci, label, asli, saran, ada, baris),
      $contohDilewati (baris 2 sama dengan baris contoh templat sehingga dilewati),
      $perkiraan (perkiraan stok per lokasi bila baris siap akan memotong stok, mis. impor penjualan),
      $catatanPratinjau (keterangan tambahan dari layanan impor).
    Jawaban tebakan ikut terkirim bersama tombol Simpan (tebakan[kunci] = terima|tolak). Baris Perlu dicek yang
    tebakannya belum dijawab tidak disimpan. resources/js/pratinjau-impor.js menghitung ulang angka di tombol Simpan.
--}}
@extends('layouts.app')

@php
    $kelas = \App\Services\Impor\ImporExcel::class;
    $tampilStatus = [
        $kelas::STATUS_SALAH    => ['Salah Isi', 'b-tolak'],
        $kelas::STATUS_CEK      => ['Perlu Dicek', 'b-awas'],
        $kelas::STATUS_SIAP     => ['Siap', 'b-setuju'],
        $kelas::STATUS_DILEWATI => [$layanan->labelDilewati(), 'b-jalan'],
    ];
    $siap = $hitung[$kelas::STATUS_SIAP];
    $chip = [
        [$tampilStatus[$kelas::STATUS_SIAP][0], 'b-setuju', $siap - $dibetulkan],
        ['Dibetulkan', 'b-aman', $dibetulkan],
        [$tampilStatus[$kelas::STATUS_CEK][0], 'b-awas', $hitung[$kelas::STATUS_CEK]],
        [$tampilStatus[$kelas::STATUS_DILEWATI][0], 'b-jalan', $hitung[$kelas::STATUS_DILEWATI]],
        [$tampilStatus[$kelas::STATUS_SALAH][0], 'b-tolak', $hitung[$kelas::STATUS_SALAH]],
    ];
@endphp

@section('crumb')
    <a class="link" href="{{ $url['kembali'] }}">{{ $layanan->judul() }}</a>
@endsection
@section('judul', \App\Support\Teks::judul('Cek '.$layanan->judul()))
@section('sub', $namaFile ?? '')

@section('content')
    <form class="panel impor-pratinjau" method="POST" action="{{ $url['simpan'] }}" data-pratinjau-impor data-siap="{{ $siap }}">
        @csrf
        <input type="hidden" name="kunci" value="{{ $kunci }}">

        <div class="panel-head">
            <div class="chips">
                @foreach ($chip as [$teks, $kelasBadge, $n])
                    @continue($n === 0 && $teks !== 'Siap')
                    <span class="badge {{ $kelasBadge }}">{{ $teks }} <span class="num">{{ $n }}</span></span>
                @endforeach
            </div>
        </div>

        @if ($siap === 0 && $hitung[$kelas::STATUS_SALAH] === 0 && $hitung[$kelas::STATUS_CEK] === 0)
            <p class="kontrol-isi impor-kosong">Tidak ada data yang berubah, jadi tidak ada yang disimpan.</p>
        @endif

        @if ($labelMode ?? null)
            <p class="kontrol-isi impor-mode-terpilih"><span class="badge b-jalan">{{ $labelMode[0] }}</span></p>
        @endif

        @if ($contohDilewati ?? false)
            <p class="kontrol-isi satuan">Baris 2 masih sama dengan baris contoh templat, jadi dilewati. Bila itu memang data Anda, pindahkan ke baris 3 lalu unggah ulang.</p>
        @endif

        @foreach ($catatanPratinjau ?? [] as $teks)
            <p class="kontrol-isi satuan">{{ $teks }}</p>
        @endforeach

        @include('admin-utama.excel._tebakan', ['tebakan' => $tebakan])

        @if (! empty($perkiraan))
            <div class="impor-perkiraan">
                @foreach ($perkiraan as $p)
                    <div class="perkiraan-wadah">
                        @include('stok._perkiraan', ['perkiraan' => $p['perkiraan'], 'judul' => 'Perkiraan Stok '.$p['lokasi']->nama])
                    </div>
                @endforeach
            </div>
        @endif

        @if ($hitung[$kelas::STATUS_SALAH] > 0)
            <p class="kontrol-isi satuan">{{ $layanan->teksBarisSalah() }}</p>
        @endif

        @include('admin-utama.excel._baris', ['baris' => $baris, 'layanan' => $layanan])

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ $url['kembali'] }}">Batal</a>
                <button type="submit" class="btn btn-merah" @disabled($siap === 0 && $hitung[$kelas::STATUS_CEK] === 0) data-tombol-simpan>
                    <x-ikon nama="ceklis" />
                    Simpan <span data-jumlah-simpan>{{ $siap }}</span> data
                </button>
            </div>
        </div>
    </form>
@endsection
