{{--
    Pesan setelah aksi (session 'sukses' dan 'galat') sebagai toast di pojok layar.
    Bila validasi form gagal, ditampilkan pengingat umum; pesan rinci ada di bawah setiap isian.
    Toast sukses hilang sendiri; toast galat tetap sampai ditutup. resources/js/toast.js juga memakai templat di sini.
--}}
@php
    $galat = session('galat');

    if (! $galat && $errors->any()) {
        $galat = 'Ada isian yang belum benar. Cek tulisan merah.';
    }

    $daftar = array_filter([
        'sukses' => session('sukses'),
        'galat'  => $galat,
    ]);
@endphp

<div class="toast-wadah" data-toast-wadah>
    @foreach ($daftar as $jenis => $teks)
        <div class="toast toast-{{ $jenis }}" role="{{ $jenis === 'galat' ? 'alert' : 'status' }}">
            <x-ikon :nama="$jenis === 'galat' ? 'peringatan' : 'ceklis'" />
            <p>{{ $teks }}</p>
            <button type="button" class="toast-tutup" data-tutup-toast aria-label="Tutup pesan">
                <x-ikon nama="silang" />
            </button>
        </div>
    @endforeach

    <template data-toast-templat>
        <div class="toast" role="status">
            <x-ikon nama="ceklis" data-ikon="sukses" />
            <x-ikon nama="peringatan" data-ikon="galat" />
            <p></p>
            <button type="button" class="toast-tutup" data-tutup-toast aria-label="Tutup pesan">
                <x-ikon nama="silang" />
            </button>
        </div>
    </template>
</div>
