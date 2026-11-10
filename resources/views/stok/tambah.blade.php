{{--
    Tambah stok langsung (popup). Variabel dari PantauStok::tambah():
      $lokasi, $rute, $bahan (BahanBaku|null bila belum dipilih), $dataBahan ([{id, nama, satuan}]),
      $saldo (stok sekarang bahan terpilih, satuan dasar), $maksKeterangan, $parameterLokasi.
    Stok langsung bertambah tanpa persetujuan dan tercatat di riwayat. tambah-stok.js mengganti satuan saat bahan dipilih.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', 'Tambah Stok')
@section('sub', $lokasi->nama)

@section('content')
    <form class="panel" method="POST" action="{{ route($rute.'.tambah.simpan', $parameterLokasi) }}" novalidate data-form-tambah-stok data-popup-isi>
        @csrf
        <script type="application/json" id="data-bahan-tambah">@json($dataBahan)</script>

        <p class="intip-catatan satuan">Stok langsung bertambah tanpa persetujuan dan tercatat di riwayat atas nama Anda.</p>

        <div class="form-grid" data-baris>
            @if ($bahan)
                <x-form.tetap label="Bahan" :nilai="$bahan->nama" name="bahan_baku_id" :value="$bahan->id"
                              :bantu="'Stok sekarang '.\App\Support\Angka::tampil($bahan->keSatuanBeli($saldo)).' '.$bahan->satuanBeli->nama" />
            @else
                <x-form.pilih-bahan name="bahan_baku_id" label="Bahan" sumber="data-bahan-tambah" wajib lebar />
            @endif

            <x-isian-jumlah bebas name="jumlah" label="Jumlah Ditambah" wajib desimal maxlength="24" placeholder="0"
                            :satuan="$bahan?->satuanBeli->nama ?? ''" data-jumlah />

            <x-form.teks name="keterangan" label="Keterangan" lebar :maxlength="$maksKeterangan" autocomplete="off"
                         placeholder="Opsional, mis. beli di pasar" />
        </div>

        <x-form.simpan :batal="route($rute, $parameterLokasi)" label="Tambah Stok" />
    </form>
@endsection
