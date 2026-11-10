{{--
    Pilih bahan baku dengan mengetik namanya (daftar menyaring otomatis).
    Butuh daftar bahan di <script type="application/json" id="{sumber}"> berisi [{id, nama, satuan}].
    Prop: name (nilai bahan_baku_id), label, value (id terpilih), teks (nama bahan terpilih),
          sumber (id elemen data), wajib, bantu, lebar, id.
    Saat bahan dipilih, elemen memicu event "bahan-dipilih" (detail: data bahan).
--}}
@props(['name', 'label', 'value' => null, 'teks' => null, 'sumber' => 'data-bahan', 'wajib' => false, 'bantu' => null, 'lebar' => false, 'id' => null])

@php
    $isian = \App\View\Isian::dari($name, $id, $bantu, $errors);
    $idDaftar = $isian->id.'-daftar';
@endphp

<x-form.field :isian="$isian" :label="$label" :wajib="$wajib" :bantu="$bantu" :lebar="$lebar">
    <div class="pilih-bahan" data-pilih-bahan data-sumber="{{ $sumber }}">
        <input {{ $attributes->class(['isian', 'galat' => $isian->adaGalat()])->merge($isian->atribut($wajib) + [
            'type'              => 'text',
            'role'              => 'combobox',
            'aria-autocomplete' => 'list',
            'aria-expanded'     => 'false',
            'aria-controls'     => $idDaftar,
            'autocomplete'      => 'off',
            'autocapitalize'    => 'none',
            'spellcheck'        => 'false',
            'enterkeyhint'      => 'next',
            'placeholder'       => 'Ketik nama bahan',
            'value'             => $teks,
            'data-pilih-teks'   => true,
        ]) }}>
        <input type="hidden" name="{{ $name }}" value="{{ old($isian->kunci, $value) }}" data-pilih-nilai>
        <ul class="pilih-daftar" id="{{ $idDaftar }}" role="listbox" aria-label="Pilihan {{ \Illuminate\Support\Str::lower($label) }}" hidden></ul>
    </div>
</x-form.field>
