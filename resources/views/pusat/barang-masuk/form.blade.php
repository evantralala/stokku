{{--
    Form catat / ubah barang masuk. Variabel dari BarangMasukController::form():
      $dokumen (BarangMasuk|null), $baris, $infoBahan, $dataBahan, $hariIni, $maksKeterangan, $maksCatatan
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('pusat.barang-masuk') }}">Barang masuk</a>
@endsection
@section('judul', $dokumen ? 'Ubah '.$dokumen->nomor : 'Catat Barang Masuk')
@section('sub', 'Stok bertambah setelah disetujui')

@section('content')
    @php $daftarBaris = old('baris', $baris); @endphp

    <form class="panel tanpa-potong" method="POST" novalidate data-popup-isi
          action="{{ $dokumen ? route('pusat.barang-masuk.perbarui', $dokumen) : route('pusat.barang-masuk.simpan') }}">
        @csrf
        @if ($dokumen)
            @method('PUT')
        @endif

        <script type="application/json" id="data-bahan">@json($dataBahan)</script>

        @if ($dataBahan->isEmpty())
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="peringatan" />
                <p>Belum ada bahan aktif. Minta Admin Utama menambahkannya dulu.</p>
            </div>
        @endif

        @if ($dokumen?->status === \App\Models\BarangMasuk::STATUS_REVISI)
            <div class="kilat kilat-galat" role="status">
                <x-ikon nama="peringatan" />
                <p>Admin Utama minta dokumen ini diperbaiki. Cek catatannya di halaman detail.</p>
            </div>
        @endif

        <div class="form-grid">
            <x-form.teks name="tanggal" label="Tanggal Diterima" type="date" wajib
                         :value="$dokumen?->tanggal->toDateString() ?? $hariIni" :max="$hariIni" />

            <x-form.teks name="catatan" label="Catatan" :value="$dokumen?->catatan"
                         :maxlength="$maksCatatan" autocomplete="off" placeholder="Opsional, mis. nomor nota" />
        </div>

        <fieldset class="kelompok-isian" data-baris-dinamis data-baris-berikutnya="{{ count($daftarBaris) ? max(array_keys($daftarBaris)) + 1 : 0 }}">
            <legend>Barang yang Diterima</legend>

            @error('baris')
                <p class="pesan-galat baris-galat" role="alert">{{ $message }}</p>
            @enderror

            <div class="daftar-baris" data-daftar-baris>
                @foreach ($daftarBaris as $i => $b)
                    @include('pusat.barang-masuk._baris', ['i' => $i, 'baris' => $b])
                @endforeach
            </div>

            <template data-templat-baris>
                @include('pusat.barang-masuk._baris', ['i' => '__i__', 'baris' => []])
            </template>

            <x-tempel-daftar />

            <div class="baris-tambah">
                <button type="button" class="btn" data-tambah-baris>
                    <x-ikon nama="plus" />
                    Tambah Barang
                </button>
            </div>
        </fieldset>

        <x-form.simpan :batal="$dokumen ? route('pusat.barang-masuk.lihat', $dokumen) : route('pusat.barang-masuk')"
                       :label="$dokumen ? 'Simpan & Kirim Ulang' : 'Kirim'" />
    </form>
@endsection
