{{--
    Isian jumlah gaya kasir: tombol − dan + besar mengapit angka (resources/js/isian-jumlah.js).
    Angka tetap bisa diketik (keyboard angka di HP); tanpa JavaScript tombol disembunyikan dan isian tetap jalan.
    Prop: name, label, value, satuan, langkah (besar tiap tekan), min, maks, desimal (boleh koma),
          wajib, bantu, id, ringkas (versi kecil untuk baris daftar), labelTersembunyi,
          bebas (jumlah boleh teks seperti 2x10 atau 500 ml: di HP ada tombol ganti keyboard angka/huruf).
    Nilai dikirim dengan koma desimal; server membacanya lewat App\Support\Angka::baca().
--}}
@props([
    'name', 'label', 'value' => null, 'satuan' => null, 'langkah' => 1, 'min' => 0, 'maks' => null,
    'desimal' => false, 'wajib' => false, 'bantu' => null, 'id' => null, 'ringkas' => false, 'labelTersembunyi' => false, 'bebas' => false,
])

@php
    $isian = \App\View\Isian::dari($name, $id, $bantu, $errors);
    $nilai = old($isian->kunci, $value);
@endphp

<x-form.field :isian="$isian" :label="$label" :wajib="$wajib" :bantu="$bantu" @class(['label-tersembunyi' => $labelTersembunyi])>
    <div @class(['isian-jumlah', 'ringkas' => $ringkas]) data-isian-jumlah data-langkah="{{ $langkah }}" data-min="{{ $min }}"{!! $maks !== null ? ' data-maks="'.e($maks).'"' : '' !!}>
        <button type="button" class="isian-jumlah-tombol" data-kurang aria-label="Kurangi {{ $label }}" aria-controls="{{ $isian->id }}" hidden>−</button>
        <span class="isian-jumlah-kotak">
            <input {{ $attributes->class(['isian-jumlah-isian', 'num', 'galat' => $isian->adaGalat()])->merge($isian->atribut($wajib) + [
                'type'         => 'text',
                'name'         => $name,
                'inputmode'    => $desimal ? 'decimal' : 'numeric',
                'autocomplete' => 'off',
                'value'        => is_numeric($nilai) ? \App\Support\Angka::isian($nilai) : $nilai,
            ]) }}>
            @if ($satuan !== null)
                <span class="isian-jumlah-satuan" aria-hidden="true">{{ $satuan }}</span>
            @endif
            @if ($bebas)
                <button type="button" class="isian-jumlah-ganti" data-ganti-keyboard aria-label="Ganti keyboard angka atau huruf" hidden>abc</button>
            @endif
        </span>
        <button type="button" class="isian-jumlah-tombol" data-tambah aria-label="Tambah {{ $label }}" aria-controls="{{ $isian->id }}" hidden>+</button>
    </div>
</x-form.field>
