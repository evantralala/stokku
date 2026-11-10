{{--
    Kosongkan stok (Admin Utama, popup). Variabel dari AdminUtama\StokController@kosongkan:
      $lokasi, $daftar (per kategori: [bahan, saldo] untuk bahan yang stoknya tidak 0, termasuk bahan nonaktif),
      $dipilih (dibuka untuk bahan tertentu: semua yang tampil tercentang), $satu (hanya satu bahan: tanpa daftar centang
      dan tanpa ketik kata konfirmasi), $menunggu (jumlah bahan dengan perubahan stok yang belum disahkan),
      $kata (kata konfirmasi), $maksAlasan, $parameterLokasi.
    Tanpa pilihan (halaman cadangan tanpa JavaScript) belum ada yang tercentang; "Pilih semua bahan" butuh JavaScript.
    Bahan yang dicentang menjadi 0 lewat penyesuaian stok; riwayat dan kartu stok tetap ada.
--}}
@extends('layouts.app')

@php
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    $lama = old('bahan');
    $jumlah = $daftar->sum(fn ($baris) => $baris->count());
@endphp

@section('crumb')
    <a class="link" href="{{ route('admin-utama.stok', $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@php $bahanSatu = $satu ? $daftar->first()->first() : null; @endphp

@section('judul', $satu ? 'Kosongkan '.$bahanSatu['bahan']->nama : 'Kosongkan Stok')
@section('sub', $lokasi->nama.' · '.($dipilih ? $jumlah.' bahan dipilih' : $jumlah.' bahan masih ada stoknya'))

@section('content')
    <form class="panel isi-stok" method="POST" action="{{ route('admin-utama.stok.kosongkan.simpan', $parameterLokasi) }}" novalidate data-popup-isi>
        @csrf

        @if ($satu)
            @php $b = $bahanSatu['bahan']; @endphp
            <input type="hidden" name="bahan[]" value="{{ $b->id }}">
            <div class="kosongkan-satu">
                <span class="nama-barang">{{ $b->nama }}</span>
                <span class="num kosongkan-satu-angka">{{ $keBeli($b, $bahanSatu['saldo']) }} → 0</span>
            </div>
            <p class="intip-catatan satuan">
                Hanya stok bahan ini di {{ $lokasi->nama }} yang menjadi 0. Riwayatnya tetap ada di kartu stok beserta alasannya.
            </p>
        @else
            <p class="intip-catatan satuan">
                Stok bahan yang dicentang di {{ $lokasi->nama }} menjadi 0, mis. barang dibuang, kedaluwarsa, atau bahan tidak dipakai lagi.
                Riwayat tetap ada: setiap bahan tercatat di kartu stok sebagai penyesuaian beserta alasannya.
            </p>
        @endif

        @if ($menunggu > 0)
            <p class="kilat kilat-info" role="note">
                <x-ikon nama="peringatan" />
                <span>{{ $menunggu }} bahan masih punya perubahan stok yang menunggu persetujuan. Bila nanti disetujui, stoknya bisa bertambah atau berkurang lagi.</span>
            </p>
        @endif

        @error('bahan')
            <p class="pesan-galat isi-stok-galat" role="alert">{{ $message }}</p>
        @enderror

        @if ($daftar->isNotEmpty() && ! $satu && ! $dipilih)
            <label class="isi-stok-pilih kosongkan-semua" data-centang-semua-bungkus hidden>
                <input type="checkbox" data-centang-semua="bahan[]">
                <span class="nama-barang">Pilih semua bahan ({{ $jumlah }})</span>
            </label>
        @endif

        @if ($daftar->isEmpty())
            <x-kosong gambar="selesai">{{ $dipilih ? 'Stok bahan yang dipilih sudah 0.' : 'Semua stok di '.$lokasi->nama.' sudah 0.' }}</x-kosong>
        @else
            @unless ($satu)
            @foreach ($daftar as $kategori => $baris)
                <h3 class="kontrol-subjudul">{{ $kategori }}</h3>
                <ul class="isi-stok-daftar">
                    @foreach ($baris as $r)
                        @php
                            $b = $r['bahan'];
                            $dicentang = $lama !== null ? in_array((string) $b->id, array_map('strval', (array) $lama), true) : $dipilih;
                        @endphp
                        <li class="isi-stok-baris">
                            <label class="isi-stok-pilih">
                                <input type="checkbox" name="bahan[]" value="{{ $b->id }}" @checked($dicentang)>
                                <span class="isi-stok-teks">
                                    <span class="nama-barang">{{ $b->nama }}</span>
                                    <span class="satuan">Stok {{ $keBeli($b, $r['saldo']) }} → 0{{ $b->aktif ? '' : ' · nonaktif' }}</span>
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            @endforeach
            @endunless

            <div class="form-grid isi-stok-keterangan">
                <x-form.teks name="alasan" label="Alasan" lebar wajib :maxlength="$maksAlasan" autocomplete="off"
                             placeholder="Mis. kedaluwarsa dibuang, bahan tidak dipakai lagi" />
                @unless ($satu)
                    <x-form.teks name="konfirmasi" :label="'Ketik '.$kata" wajib autocomplete="off"
                                 autocapitalize="characters" spellcheck="false" :placeholder="$kata" />
                @endunless
            </div>
        @endif

        <div class="simpan-bar lekat">
            <div class="simpan-aksi">
                <a class="btn" href="{{ route('admin-utama.stok', $parameterLokasi) }}" data-popup-batal>Batal</a>
                @if ($daftar->isNotEmpty())
                    <button type="submit" class="btn btn-hapus">
                        <x-ikon nama="sampah" />
                        {{ $satu ? 'Kosongkan' : 'Kosongkan Stok' }}
                    </button>
                @endif
            </div>
        </div>
    </form>
@endsection
