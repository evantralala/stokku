{{--
    Form kirim barang dari cabang. Variabel dari KirimBarangController::create():
      $lokasi, $baris, $pilihanTujuan ([id => nama]; Gudang Pusat ditandai "retur"), $infoBahan, $dataBahan, $maksCatatan
    Tanpa persetujuan: stok cabang langsung berkurang saat disimpan, lokasi tujuan menghitung barang saat tiba.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('cabang.kirim-barang') }}">Kirim barang</a>
@endsection
@section('judul', 'Kirim Barang')
@section('sub', 'Dari '.$lokasi->nama)

@section('content')
    @php $daftarBaris = old('baris', $baris); @endphp

    <form class="panel tanpa-potong" method="POST" action="{{ route('cabang.kirim-barang.simpan') }}" novalidate data-form-info-stok data-popup-isi
          data-konfirmasi="Stok {{ $lokasi->nama }} langsung berkurang. Cetak surat jalan setelah disimpan untuk dibawa bersama barang."
          data-konfirmasi-judul="Kirim Barang Sekarang?"
          data-konfirmasi-tombol="Ya, Kirim">
        @csrf

        <script type="application/json" id="data-bahan">@json($dataBahan)</script>

        <div class="form-grid">
            @if ($pilihanTujuan === [])
                <p class="pesan-galat lebar" role="alert">Belum ada lokasi tujuan yang aktif. Hubungi Admin Utama.</p>
            @else
                <x-form.pilihan name="ke_cabang_id" label="Tujuan" :pilihan="$pilihanTujuan" kosong="Pilih Tujuan" wajib />
            @endif
        </div>

        <fieldset class="kelompok-isian" data-baris-dinamis data-baris-berikutnya="{{ count($daftarBaris) ? max(array_keys($daftarBaris)) + 1 : 0 }}">
            <legend>Bahan yang Dikirim</legend>

            @error('baris')
                <p class="pesan-galat baris-galat" role="alert">{{ $message }}</p>
            @enderror

            <div class="daftar-baris" data-daftar-baris>
                @foreach ($daftarBaris as $i => $b)
                    @include('cabang.kirim-barang._baris', ['i' => $i, 'baris' => $b])
                @endforeach
            </div>

            <template data-templat-baris>
                @include('cabang.kirim-barang._baris', ['i' => '__i__', 'baris' => []])
            </template>

            <x-tempel-daftar />

            <div class="baris-tambah">
                <button type="button" class="btn" data-tambah-baris>
                    <x-ikon nama="plus" />
                    Tambah Bahan
                </button>
            </div>
        </fieldset>

        <div class="form-grid">
            <x-form.area name="catatan" label="Catatan" :maxlength="$maksCatatan" lebar
                         placeholder="Opsional, mis. retur karena kemasan rusak" />
        </div>

        <x-form.simpan :batal="route('cabang.kirim-barang')" label="Kirim Barang" />
    </form>
@endsection
