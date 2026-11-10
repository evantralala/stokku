{{--
    Pilihan pendek berupa kotak yang tinggal ditekan (radio), pengganti dropdown untuk 2–6 pilihan.
    Prop: name, label, pilihan ([nilai => teks]), value, wajib, bantu, lebar, id, kolom (jumlah kolom di layar lebar: 2|3|4).
    Di HP selalu dua kolom.
--}}
@props(['name', 'label', 'pilihan' => [], 'value' => null, 'wajib' => false, 'bantu' => null, 'lebar' => false, 'id' => null, 'kolom' => 2])

@php
    $isian = \App\View\Isian::dari($name, $id, $bantu, $errors);
    $terpilih = (string) old($isian->kunci, $value);
    $dijelaskan = trim(($isian->idBantu ?? '').' '.($isian->adaGalat() ? $isian->idGalat : ''));
@endphp

<fieldset {{ $attributes->class(['field', 'pilih-kotak-field', 'lebar' => $lebar]) }}
          @if ($dijelaskan !== '') aria-describedby="{{ $dijelaskan }}" @endif>
    <legend>
        {{ \App\Support\Teks::judul($label) }}
        @if ($wajib)
            <span class="wajib" aria-hidden="true">*</span>
        @endif
    </legend>

    <div @class(['pilih-kotak', 'kolom-'.$kolom, 'galat' => $isian->adaGalat()])>
        @foreach ($pilihan as $nilai => $teks)
            <label class="pilih-kotak-opsi">
                <input type="radio" name="{{ $name }}" value="{{ $nilai }}" @checked($terpilih === (string) $nilai)
                       @if ($loop->first) id="{{ $isian->id }}" @endif @if ($wajib) required @endif>
                <span>{{ $teks }}</span>
            </label>
        @endforeach
    </div>

    @if ($bantu)
        <span class="bantu" id="{{ $isian->idBantu }}">{{ $bantu }}</span>
    @endif

    @if ($isian->adaGalat())
        <span class="pesan-galat" id="{{ $isian->idGalat }}">{{ $isian->pesanGalat }}</span>
    @endif
</fieldset>
