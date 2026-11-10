{{--
    Form versi resep. Variabel dari ResepController@create:
      $menu, $tanggal, $hariIni, $versiAda, $baris, $infoBahan, $dataBahan, $disalin, $pilihanSalin
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('admin-utama.menu.resep', $menu) }}">Resep {{ $menu->nama }}</a>
@endsection
@section('judul', 'Ubah Resep')
@section('sub', $menu->nama)

@section('content')
    @if ($dataBahan->isEmpty())
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Belum ada bahan baku aktif. <a class="link" href="{{ route('admin-utama.bahan-baku.tambah') }}" data-popup>Tambah dulu</a></p>
        </div>
    @endif

    @if ($disalin)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Disalin dari {{ $disalin->nama }}. Cek takarannya, lalu simpan.</p>
        </div>
    @endif

    @if ($pilihanSalin)
        <form class="panel salin-resep" method="GET" action="{{ route('admin-utama.menu.resep.ubah', $menu) }}">
            <input type="hidden" name="tanggal" value="{{ $tanggal }}">
            <label for="salin">Mulai dari Resep Menu Lain</label>
            <div class="salin-resep-isi">
                <select class="isian" id="salin" name="salin">
                    <option value="">Pilih Menu</option>
                    @foreach ($pilihanSalin as $id => $nama)
                        <option value="{{ $id }}" @selected($disalin?->id === $id)>{{ $nama }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn">Salin</button>
            </div>
        </form>
    @endif

    <script type="application/json" id="data-bahan">@json($dataBahan)</script>

    @php $daftarBaris = old('baris', $baris); @endphp

    <form class="panel tanpa-potong" method="POST" action="{{ route('admin-utama.menu.resep.simpan', $menu) }}" novalidate>
        @csrf

        <div class="form-grid">
            <x-form.teks name="berlaku_sejak" label="Berlaku Mulai" type="date" :value="$tanggal" wajib
                         :min="$hariIni"
                         bantu="Penjualan sebelum tanggal ini tetap pakai resep lama." />

            <p class="panduan-resep">
                Takaran untuk <strong>1 porsi</strong>, dalam satuan resep bahan (kg ditulis gram, liter ditulis ml).
                @if ($versiAda && ! $disalin)
                    Resep tanggal ini akan <strong>diganti</strong>.
                @endif
            </p>
        </div>

        <fieldset class="kelompok-isian" data-baris-dinamis data-baris-berikutnya="{{ count($daftarBaris) ? max(array_keys($daftarBaris)) + 1 : 0 }}">
            <legend>Bahan</legend>

            @error('baris')
                <p class="pesan-galat baris-galat" role="alert">{{ $message }}</p>
            @enderror

            <div class="daftar-baris" data-daftar-baris>
                @foreach ($daftarBaris as $i => $b)
                    @include('admin-utama.menu._baris-resep', ['i' => $i, 'baris' => $b])
                @endforeach
            </div>

            <template data-templat-baris>
                @include('admin-utama.menu._baris-resep', ['i' => '__i__', 'baris' => []])
            </template>

            <div class="baris-tambah">
                <button type="button" class="btn" data-tambah-baris>
                    <x-ikon nama="plus" />
                    Tambah Bahan
                </button>
            </div>
        </fieldset>

        <x-form.simpan :batal="route('admin-utama.menu.resep', $menu)" label="Simpan Resep" />
    </form>
@endsection
