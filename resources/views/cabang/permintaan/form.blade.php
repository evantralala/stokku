{{--
    Form buat permintaan. Variabel dari PermintaanController::form():
      $lokasi, $baris, $infoBahan, $jumlahUsulan, $dataBahan, $maksAlasan
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('cabang.permintaan') }}">Permintaan</a>
@endsection
@section('judul', 'Buat Permintaan')
@section('sub', 'Ke Gudang Pusat')

@section('content')
    @php $daftarBaris = old('baris', $baris); @endphp

    <form class="panel tanpa-potong" method="POST" action="{{ route('cabang.permintaan.simpan') }}" novalidate data-form-info-stok data-popup-isi>
        @csrf

        <script type="application/json" id="data-bahan">@json($dataBahan)</script>

        @if ($jumlahUsulan > 0 && ! request()->boolean('usulan'))
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="peringatan" />
                <p>
                    {{ $jumlahUsulan }} bahan di bawah stok minimum.
                    <a class="link" href="{{ route('cabang.permintaan.buat', ['usulan' => 1]) }}" data-popup="panjang">Isi otomatis</a>
                </p>
            </div>
        @endif

        <fieldset class="kelompok-isian" data-baris-dinamis data-baris-berikutnya="{{ count($daftarBaris) ? max(array_keys($daftarBaris)) + 1 : 0 }}">
            <legend>Bahan yang Diminta</legend>

            @error('baris')
                <p class="pesan-galat baris-galat" role="alert">{{ $message }}</p>
            @enderror

            <div class="daftar-baris" data-daftar-baris>
                @foreach ($daftarBaris as $i => $b)
                    @include('cabang.permintaan._baris', ['i' => $i, 'baris' => $b])
                @endforeach
            </div>

            <template data-templat-baris>
                @include('cabang.permintaan._baris', ['i' => '__i__', 'baris' => []])
            </template>

            <x-tempel-daftar />

            <div class="baris-tambah">
                <button type="button" class="btn" data-tambah-baris>
                    <x-ikon nama="plus" />
                    Tambah Bahan
                </button>
            </div>
        </fieldset>

        <x-form.simpan :batal="route('cabang.permintaan')" label="Kirim Permintaan" />
    </form>
@endsection
