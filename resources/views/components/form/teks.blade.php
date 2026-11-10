{{--
    Isian teks satu baris.
    Prop: name, label, value, wajib, bantu, lebar (selebar form), id, type (text|email|tel|password|search).
    Atribut lain (maxlength, placeholder, inputmode, autocomplete) diteruskan ke <input>.
--}}
@props(['name', 'label', 'value' => null, 'wajib' => false, 'bantu' => null, 'lebar' => false, 'id' => null, 'type' => 'text'])

@php $isian = \App\View\Isian::dari($name, $id, $bantu, $errors); @endphp

<x-form.field :isian="$isian" :label="$label" :wajib="$wajib" :bantu="$bantu" :lebar="$lebar">
    <input {{ $attributes->class(['isian', 'galat' => $isian->adaGalat()])->merge($isian->atribut($wajib) + [
        'type'  => $type,
        'name'  => $name,
        'value' => $type === 'password' ? null : old($isian->kunci, $value),
    ]) }}>
</x-form.field>