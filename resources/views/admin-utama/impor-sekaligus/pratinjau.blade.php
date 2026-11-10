{{--
    Pratinjau Impor Sekaligus. Variabel dari ImporSekaligusController::pratinjau:
      $lembar ([jenis => label, nama, layanan, galat, urut, hitung, dibetulkan]), $tebakan (gabungan semua lembar),
      $kunci, $namaFile, $diabaikan (nama lembar yang tidak dikenali), $labelMode (Ganti/Tambah bila ada lembar Stok), $kembali.
--}}
@extends('layouts.app')

@php
    $kelas = \App\Services\Impor\ImporExcel::class;
    $siap = $lembar->sum(fn ($l) => $l['hitung'][$kelas::STATUS_SIAP]);
    $cek = $lembar->sum(fn ($l) => $l['hitung'][$kelas::STATUS_CEK]);
    $salah = $lembar->sum(fn ($l) => $l['hitung'][$kelas::STATUS_SALAH]);
@endphp

@section('crumb')
    <a class="link" href="{{ $kembali }}">Dashboard</a>
@endsection
@section('judul', 'Cek Impor Sekaligus')
@section('sub', $namaFile)

@section('content')
    <form class="panel impor-pratinjau" method="POST" action="{{ route('admin-utama.impor-sekaligus.simpan') }}" data-pratinjau-impor data-siap="{{ $siap }}">
        @csrf
        <input type="hidden" name="kunci" value="{{ $kunci }}">

        <div class="panel-head">
            <div class="chips">
                @foreach ($lembar as $l)
                    <span class="badge {{ $l['galat'] || $l['hitung'][$kelas::STATUS_SALAH] > 0 ? 'b-tolak' : 'b-setuju' }}">{{ $l['label'] }} <span class="num">{{ $l['hitung'][$kelas::STATUS_SIAP] }}</span></span>
                @endforeach
            </div>
        </div>

        @if ($siap === 0 && $salah === 0 && $cek === 0)
            <p class="kontrol-isi impor-kosong">Tidak ada data yang berubah, jadi tidak ada yang disimpan.</p>
        @endif

        @if ($labelMode)
            <p class="kontrol-isi impor-mode-terpilih">Stok: <span class="badge b-jalan">{{ $labelMode }}</span></p>
        @endif

        @if ($diabaikan !== [])
            <p class="kontrol-isi satuan">Lembar tidak dikenali, tidak dibaca: {{ implode(', ', $diabaikan) }}.</p>
        @endif

        @include('admin-utama.excel._tebakan', ['tebakan' => $tebakan, 'awalanBaris' => ''])

        @if ($salah > 0)
            <p class="kontrol-isi satuan">Baris salah isi tidak ikut disimpan. Betulkan di file lalu unggah ulang, atau simpan yang siap dulu.</p>
        @endif

        @foreach ($lembar as $l)
            @php
                $h = $l['hitung'];
                $chip = [
                    ['Siap', 'b-setuju', $h[$kelas::STATUS_SIAP] - $l['dibetulkan']],
                    ['Dibetulkan', 'b-aman', $l['dibetulkan']],
                    ['Perlu Dicek', 'b-awas', $h[$kelas::STATUS_CEK]],
                    [$l['layanan']->labelDilewati(), 'b-jalan', $h[$kelas::STATUS_DILEWATI]],
                    ['Salah Isi', 'b-tolak', $h[$kelas::STATUS_SALAH]],
                ];
            @endphp
            <section class="impor-lembar" aria-labelledby="lembar-{{ $loop->index }}">
                <div class="impor-lembar-kepala">
                    <h2 id="lembar-{{ $loop->index }}">{{ $l['label'] }}</h2>
                    @if (mb_strtolower($l['nama']) !== mb_strtolower($l['label']))
                        <span class="satuan">Lembar {{ $l['nama'] }}</span>
                    @endif
                    <div class="chips">
                        @foreach ($chip as [$teks, $kelasBadge, $n])
                            @continue($n === 0)
                            <span class="badge {{ $kelasBadge }}">{{ $teks }} <span class="num">{{ $n }}</span></span>
                        @endforeach
                    </div>
                </div>
                @if ($l['galat'])
                    <p class="kontrol-isi pesan-galat">{{ $l['galat'] }}</p>
                @endif
                @include('admin-utama.excel._baris', ['baris' => $l['urut'], 'layanan' => $l['layanan']])
            </section>
        @endforeach

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ $kembali }}">Batal</a>
                <button type="submit" class="btn btn-merah" @disabled($siap === 0 && $cek === 0) data-tombol-simpan>
                    <x-ikon nama="ceklis" />
                    Simpan <span data-jumlah-simpan>{{ $siap }}</span> data
                </button>
            </div>
        </div>
    </form>
@endsection
