{{--
    Pilihan dari daftar pendek (dropdown).
    Prop: name, label, pilihan ([nilai => teks]), value, kosong (teks pilihan kosong, null = tanpa pilihan kosong),
          wajib, bantu, lebar, id.
--}}
@props(['name', 'label', 'pilihan' => [], 'value' => null, 'kosong' => null, 'wajib' => false, 'bantu' => null, 'lebar' => false, 'id' => null])

@php
    $isian = \App\View\Isian::dari($name, $id, $bantu, $errors);
    $terpilih = (string) old($isian->kunci, $value);
@endphp

<x-form.field :isian="$isian" :label="$label" :wajib="$wajib" :bantu="$bantu" :lebar="$lebar">
    <select {{ $attributes->class(['isian', 'galat' => $isian->adaGalat()])->merge($isian->atribut($wajib) + ['name' => $name]) }}>
        @if ($kosong !== null)
            <option value="">{{ $kosong }}</option>
        @endif
        @foreach ($pilihan as $nilai => $teks)
            <option value="{{ $nilai }}" @selected($terpilih === (string) $nilai)>{{ $teks }}</option>
        @endforeach
    </select>
</x-form.field>