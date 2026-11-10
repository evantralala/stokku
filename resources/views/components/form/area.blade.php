{{-- Isian teks beberapa baris. Prop sama dengan form.teks (tanpa type). --}}
@props(['name', 'label', 'value' => null, 'wajib' => false, 'bantu' => null, 'lebar' => true, 'id' => null])

@php $isian = \App\View\Isian::dari($name, $id, $bantu, $errors); @endphp

<x-form.field :isian="$isian" :label="$label" :wajib="$wajib" :bantu="$bantu" :lebar="$lebar">
    <textarea {{ $attributes->class(['isian', 'galat' => $isian->adaGalat()])->merge($isian->atribut($wajib) + [
        'name' => $name,
        'rows' => 3,
    ]) }}>{{ old($isian->kunci, $value) }}</textarea>
</x-form.field>