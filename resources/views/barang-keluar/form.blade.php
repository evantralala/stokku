{{--
    Form catat / ubah barang rusak. Variabel dari KelolaBarangKeluar::form():
      $dokumen, $rute, $lokasi, $baris, $infoBahan, $dataBahan, $hariIni, $maksAlasan
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Barang rusak</a>
@endsection
@section('judul', $dokumen ? 'Ubah '.$dokumen->nomor : 'Catat Barang Rusak')
@section('sub', 'Stok berkurang setelah disetujui')

@section('content')
    @php $daftarBaris = old('baris', $baris); @endphp

    <form class="panel tanpa-potong" method="POST" novalidate data-form-info-stok data-popup-isi
          action="{{ $dokumen ? route($rute.'.perbarui', $dokumen) : route($rute.'.simpan') }}">
        @csrf
        @if ($dokumen)
            @method('PUT')
        @endif

        <script type="application/json" id="data-bahan">@json($dataBahan)</script>

        @if ($dokumen?->status === \App\Models\BarangKeluar::STATUS_REVISI)
            <div class="kilat kilat-galat" role="status">
                <x-ikon nama="peringatan" />
                <p>Admin Utama minta dokumen ini diperbaiki. Cek catatannya di halaman detail.</p>
            </div>
        @endif

        <div class="form-grid">
            <x-form.teks name="tanggal" label="Tanggal Kejadian" type="date" wajib
                         :value="$dokumen?->tanggal->toDateString() ?? $hariIni" :max="$hariIni" />
        </div>

        <fieldset class="kelompok-isian" data-baris-dinamis data-baris-berikutnya="{{ count($daftarBaris) ? max(array_keys($daftarBaris)) + 1 : 0 }}">
            <legend>Barang yang Rusak</legend>

            @error('baris')
                <p class="pesan-galat baris-galat" role="alert">{{ $message }}</p>
            @enderror

            <div class="daftar-baris" data-daftar-baris>
                @foreach ($daftarBaris as $i => $b)
                    @include('barang-keluar._baris', ['i' => $i, 'baris' => $b])
                @endforeach
            </div>

            <template data-templat-baris>
                @include('barang-keluar._baris', ['i' => '__i__', 'baris' => []])
            </template>

            <div class="baris-tambah">
                <button type="button" class="btn" data-tambah-baris>
                    <x-ikon nama="plus" />
                    Tambah Barang
                </button>
            </div>
        </fieldset>

        <x-form.simpan :batal="$dokumen ? route($rute.'.lihat', $dokumen) : route($rute)"
                       :label="$dokumen ? 'Simpan & Kirim Ulang' : 'Kirim'" />
    </form>
@endsection
