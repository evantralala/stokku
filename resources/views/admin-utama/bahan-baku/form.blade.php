{{-- Form tambah / ubah bahan baku. Variabel dari BahanBakuController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Bahan baku</a>
@endsection
@section('judul', $item ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku')
@section('sub', $item?->nama ?? '')

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="panjang">
        @if (! $pilihanKategori || ! $pilihanSatuan)
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="peringatan" />
                <p>
                    Isi dulu
                    @unless ($pilihanKategori) <a class="link" href="{{ route('admin-utama.kategori.tambah') }}" data-popup>kategori bahan</a> @endunless
                    @unless ($pilihanKategori || $pilihanSatuan) dan @endunless
                    @unless ($pilihanSatuan) <a class="link" href="{{ route('admin-utama.satuan.tambah') }}" data-popup>satuan</a> @endunless
                    sebelum menambah bahan baku.
                </p>
            </div>
        @endif

        <form class="panel" method="POST"
              action="{{ $item ? route($rute.'.perbarui', $item) : route($rute.'.simpan') }}" enctype="multipart/form-data" novalidate>
            @csrf
            @if ($item)
                @method('PUT')
            @endif

            <div class="form-grid">
                <x-form.teks name="nama" label="Nama Bahan" :value="$item?->nama" wajib lebar
                             :maxlength="$maks['nama']" autocomplete="off" placeholder="Contoh: Daun bawang" />

                <x-form.teks name="nama_lain" label="Nama Lain" :value="$item?->nama_lain" lebar maxlength="500" autocomplete="off"
                             placeholder="Opsional, pisahkan dengan koma. Contoh: telor, egg" />

                <x-form.foto :src="$item?->urlFoto()" :nama="$item?->nama ?? ''" />

                <x-form.pilihan name="kategori_id" label="Kategori" :pilihan="$pilihanKategori"
                                :value="$item?->kategori_id" kosong="Pilih Kategori" wajib />

                <x-form.pilihan name="satuan_id" label="Satuan" :pilihan="$pilihanSatuan"
                                :value="$item?->satuan_beli_id" kosong="Pilih Satuan" wajib
                                :bantu="$satuanTerkunci ? 'Sudah punya stok, hanya bisa diganti ke satuan sejenis (mis. kg ke gram).' : 'Untuk kg dan liter, resep ditulis dalam gram dan ml.'" />

                <x-isian-jumlah name="toleransi_persen" label="Toleransi Selisih" desimal satuan="%" maxlength="6" wajib
                                :value="\App\Support\Angka::isian($item?->toleransi_persen ?? $toleransiBawaan, 2)"
                                bantu="Selisih di bawah ini dianggap wajar." />
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan Bahan'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item)
            <x-master.kelola :rute="$rute" :item="$item" label="bahan baku" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak muncul di pilihan bahan pada transaksi baru" />
        @endif
    </div>
@endsection
