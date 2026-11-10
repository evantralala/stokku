{{--
    Ubah stok satu bahan (popup). Variabel dari PantauStok::ubah():
      $lokasi, $rute, $bahan, $saldo (stok saat form dibuka, satuan dasar),
      $maksKeterangan, $parameterLokasi.
    Dua cara (cara): Kurangi = isi jumlah yang dikurangi (stok baru dihitung sistem); Ganti = isi stok sebenarnya.
    Stok langsung berubah tanpa persetujuan; selisihnya tercatat di riwayat sebagai penyesuaian.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', 'Ubah Stok')
@section('sub', $bahan->nama.' · '.$lokasi->nama)

@section('content')
    @php $stokSekarang = \App\Support\Angka::tampil($bahan->keSatuanBeli($saldo)).' '.$bahan->satuanBeli->nama; @endphp

    <form class="panel" method="POST" action="{{ route($rute.'.ubah.simpan', [$bahan] + $parameterLokasi) }}" novalidate data-popup-isi>
        @csrf
        <input type="hidden" name="stok_lama" value="{{ $saldo }}">

        <p class="intip-catatan satuan">Stok langsung berubah tanpa persetujuan dan tercatat di riwayat atas nama Anda.</p>

        @php $cara = old('cara', \App\Http\Requests\UbahStokRequest::CARA_KURANGI); @endphp
        <div class="form-grid">
            <x-form.tetap label="Stok Sekarang" :nilai="$stokSekarang" />

            <fieldset class="impor-mode lebar">
                <legend class="label-isian">Cara Ubah</legend>
                <label class="impor-mode-pilih">
                    <input type="radio" name="cara" value="kurangi" @checked($cara === 'kurangi')>
                    <span class="nama-barang">Kurangi</span>
                </label>
                <label class="impor-mode-pilih">
                    <input type="radio" name="cara" value="samakan" @checked($cara === 'samakan')>
                    <span class="nama-barang">Ganti</span>
                </label>
            </fieldset>

            <div class="lebar" data-tampil-jika="cara:kurangi" @if ($cara !== 'kurangi') hidden @endif>
                <x-isian-jumlah bebas name="jumlah_kurang" label="Jumlah Dikurangi" wajib desimal maxlength="24" :satuan="$bahan->satuanBeli->nama" />
            </div>

            <div class="lebar" data-tampil-jika="cara:samakan" @if ($cara !== 'samakan') hidden @endif>
                <x-isian-jumlah bebas name="stok_baru" label="Stok Baru" wajib desimal maxlength="24"
                                :value="max(0, $bahan->keSatuanBeli($saldo))" :satuan="$bahan->satuanBeli->nama" />
            </div>

            <x-form.teks name="keterangan" label="Keterangan" lebar :maxlength="$maksKeterangan" autocomplete="off"
                         placeholder="Opsional, mis. hasil hitung ulang rak" />
        </div>

        <x-form.simpan :batal="route($rute, $parameterLokasi)" label="Simpan Stok" />
    </form>
@endsection
