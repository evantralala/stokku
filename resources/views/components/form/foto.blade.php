{{--
    Isian foto data master: pratinjau, tombol pilih/ganti (di HP bisa langsung dari kamera), dan centang hapus.
    Prop: src (URL foto sekarang atau null), nama (untuk huruf awal saat belum ada foto).
    Form pemakainya wajib enctype="multipart/form-data".
--}}
@props(['src' => null, 'nama' => ''])

@php
    $bantu = 'JPG, PNG, atau WebP, maks. '.(\App\Services\FotoService::MAKS_KB / 1024).' MB. Diperkecil otomatis.';
    $isian = \App\View\Isian::dari('foto', null, $bantu, $errors);
@endphp

<div {{ $attributes->class(['field', 'lebar', 'isian-foto']) }} data-isian-foto>
    <span class="label-isian">Foto</span>

    <div class="isian-foto-baris">
        <x-foto :src="$src" :nama="$nama" ukuran="sedang" data-foto-pratinjau />

        <div class="isian-foto-kendali">
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="isian-foto-file"
                   id="{{ $isian->id }}" aria-describedby="{{ trim($isian->idBantu.' '.($isian->adaGalat() ? $isian->idGalat : '')) }}"
                   @if ($isian->adaGalat()) aria-invalid="true" @endif>
            <label class="btn btn-sm" id="tombol-{{ $isian->id }}" for="{{ $isian->id }}">
                <x-ikon nama="kamera" />
                <span data-foto-teks>{{ $src ? 'Ganti Foto' : 'Pilih Foto' }}</span>
            </label>

            @if ($src)
                <label class="isian-foto-hapus">
                    <input type="checkbox" name="hapus_foto" value="1" data-foto-hapus @checked(old('hapus_foto'))>
                    Hapus Foto
                </label>
            @endif
        </div>
    </div>

    <span class="bantu" id="{{ $isian->idBantu }}">{{ $bantu }}</span>

    @if ($isian->adaGalat())
        <span class="pesan-galat" id="{{ $isian->idGalat }}">{{ $isian->pesanGalat }} Pilih fotonya lagi.</span>
    @endif
</div>
