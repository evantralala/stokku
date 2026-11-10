{{-- Form tambah / ubah lokasi. Variabel dari LokasiController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Lokasi</a>
@endsection
@section('judul', $item ? 'Ubah Lokasi' : 'Tambah Lokasi')
@section('sub', $item?->nama ?? '')

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="panjang">
        <form class="panel" method="POST"
              action="{{ $item ? route($rute.'.perbarui', $item) : route($rute.'.simpan') }}" novalidate>
            @csrf
            @if ($item)
                @method('PUT')
            @endif

            <div class="form-grid">
                <x-form.teks name="nama" label="Nama Lokasi" :value="$item?->nama" wajib lebar
                             :maxlength="$maks['nama']" autocomplete="off"
                             placeholder="Contoh: Cabang Jati" />

                <x-form.teks name="kode" label="Kode Singkat" :value="$item?->kode" wajib
                             :maxlength="$maks['kode']" autocomplete="off" autocapitalize="characters"
                             class="huruf-besar"
                             bantu="Mis. JTI. Muncul di nomor dokumen."
                             placeholder="JTI" />

                @if ($jenisTerkunci)
                    <x-form.tetap label="Jenis Lokasi" :nilai="$item->label_jenis"
                                  name="jenis" :value="$item->jenis"
                                  bantu="Tidak bisa diubah karena lokasi ini sudah dipakai." />
                @else
                    <x-form.pilih-kotak name="jenis" label="Jenis Lokasi" :pilihan="$pilihanJenis"
                                        :value="$item?->jenis ?? \App\Models\Cabang::JENIS_CABANG" wajib
                                        :bantu="$pusatLain ? 'Gudang Pusat aktif saat ini: '.$pusatLain->nama.'. Hanya boleh satu.' : 'Hanya boleh ada satu Gudang Pusat aktif.'" />
                @endif

                <x-form.teks name="alamat" label="Alamat" :value="$item?->alamat" lebar
                             :maxlength="$maks['alamat']" placeholder="Contoh: Jl. Merdeka No. 10, Jakarta" />
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan Lokasi'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item?->isPusat() && $item->aktif)
            <section class="panel" aria-labelledby="judul-pindah-pusat">
                <div class="panel-head">
                    <div>
                        <h2 id="judul-pindah-pusat"><x-ikon-judul nama="truk" />Pindah Gudang Pusat</h2>
                        <div class="sub">Gudang pindah tempat? Stok, Admin Pusat, dan stok minimum dipindah ke lokasi baru sekaligus.</div>
                    </div>
                </div>
                <div class="simpan-bar">
                    <div class="simpan-aksi">
                        <a class="btn" href="{{ route('admin-utama.lokasi.pindah-pusat') }}">
                            <x-ikon nama="truk" />
                            Pindah Gudang Pusat…
                        </a>
                    </div>
                </div>
            </section>
        @endif

        @if ($item)
            <x-master.kelola :rute="$rute" :item="$item" label="lokasi" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak muncul di pilihan lokasi pada transaksi dan laporan baru" />
        @endif
    </div>
@endsection