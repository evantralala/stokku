{{--
    Isi stok bahan yang dicentang di halaman Stok, atau semua yang habis/menipis bila tidak ada yang dicentang (popup panjang). Variabel dari PantauStok::isi():
      $lokasi, $rute, $mode (minta | tambah), $daftar (baris IsiStokService::daftar tanpa yang sudah diminta),
      $sudahDiminta (nama bahan yang masih dalam permintaan berjalan), $persen,
      $maksKeterangan, $parameterLokasi.
    Setiap baris tercentang dengan jumlah usulan minimum + persen (satuan beli); jumlah bisa diubah dan centang bisa dilepas sebelum dikirim.
    Mode minta: semua baris menjadi satu permintaan. Mode tambah: stok langsung bertambah per bahan.
    Setelah validasi gagal, baris yang tadi dicentang tetap tercentang (old('baris') hanya memuat baris tercentang).
--}}
@extends('layouts.app')

@php
    $minta = $mode === \App\Services\IsiStokService::MODE_MINTA;
    $lama = old('baris');
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    $teksPersen = \App\Support\Angka::tampil($persen).'%';
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', $minta ? 'Minta Stok' : 'Isi Stok')
@section('sub', $lokasi->nama.' · '.$daftar->count().' bahan')

@section('content')
    <form class="panel isi-stok" method="POST" action="{{ route($rute.'.isi.simpan', $parameterLokasi) }}" novalidate data-popup-isi>
        @csrf

        <p class="intip-catatan satuan">
            @if ($minta)
                Semua bahan yang dicentang menjadi satu permintaan ke Admin Utama. Jumlah awal = stok minimum + {{ $teksPersen }}; ubah dulu bila perlu.
            @else
                Stok {{ $lokasi->nama }} langsung bertambah dan tercatat di riwayat. Jumlah awal = stok minimum + {{ $teksPersen }}; ubah dulu bila perlu.
            @endif
        </p>

        @error('baris')
            <p class="pesan-galat isi-stok-galat" role="alert">{{ $message }}</p>
        @enderror

        @if ($sudahDiminta->isNotEmpty())
            <p class="intip-catatan satuan">Sudah dalam permintaan yang berjalan: {{ $sudahDiminta->implode(', ') }}.</p>
        @endif

        @if ($daftar->isEmpty())
            <x-kosong gambar="selesai">Tidak ada bahan habis atau menipis yang perlu diisi.</x-kosong>
        @else
            <ul class="isi-stok-daftar">
                @foreach ($daftar as $r)
                    @php
                        $b = $r['bahan'];
                        $awalan = "baris[{$b->id}]";
                        $dicentang = $lama === null || array_key_exists($b->id, (array) $lama);
                    @endphp
                    <li class="isi-stok-baris">
                        <label class="isi-stok-pilih">
                            <input type="checkbox" name="{{ $awalan }}[pilih]" value="1" @checked($dicentang)>
                            <span class="isi-stok-teks">
                                <span class="nama-barang">{{ $b->nama }}</span>
                                <span class="satuan">
                                    Stok {{ $keBeli($b, $r['saldo']) }} ·
                                    {{ $r['minimum'] > 0 ? 'Min. '.$keBeli($b, $r['minimum']) : 'Min. belum diatur' }}
                                </span>
                            </span>
                            <x-lampu-stok :status="$r['status']" :rinci="false" />
                        </label>
                        <input type="hidden" name="{{ $awalan }}[bahan_baku_id]" value="{{ $b->id }}">

                        <div class="isi-stok-isian">
                            <x-isian-jumlah bebas :name="$awalan.'[qty]'" :label="$minta ? 'Jumlah Diminta' : 'Jumlah Ditambah'"
                                            desimal :value="$r['usulan']" :satuan="$b->satuanBeli->nama" maxlength="24" placeholder="0"
                                            :bantu="$r['usulan'] !== null ? 'Usulan: minimum + '.$teksPersen : 'Minimum belum diatur, isi sendiri'" />
                        </div>
                    </li>
                @endforeach
            </ul>

            @unless ($minta)
                <div class="form-grid isi-stok-keterangan">
                    <x-form.teks name="keterangan" label="Keterangan" lebar :maxlength="$maksKeterangan" autocomplete="off"
                                 placeholder="Opsional, mis. belanja pasar pagi" />
                </div>
            @endunless
        @endif

        @if ($daftar->isNotEmpty())
            <x-form.simpan :batal="route($rute, $parameterLokasi)" :label="$minta ? 'Kirim Permintaan' : 'Tambah Stok'" />
        @else
            <div class="simpan-bar lekat">
                <div class="simpan-aksi">
                    <a class="btn" href="{{ route($rute, $parameterLokasi) }}" data-popup-batal>Tutup</a>
                </div>
            </div>
        @endif
    </form>
@endsection
