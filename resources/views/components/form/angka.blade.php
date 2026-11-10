{{--
    Isian angka dengan keyboard angka di HP.
    Prop: name, label, value, wajib, bantu, lebar, id, desimal (boleh koma),
          satuan (teks di kanan isian; string kosong = disiapkan untuk diisi JavaScript).
--}}
@props(['name', 'label', 'value' => null, 'wajib' => false, 'bantu' => null, 'lebar' => false, 'id' => null, 'desimal' => false, 'satuan' => null])

@php $isian = \App\View\Isian::dari($name, $id, $bantu, $errors); @endphp

<x-form.field :isian="$isian" :label="$label" :wajib="$wajib" :bantu="$bantu" :lebar="$lebar">
    <div class="isian-satuan">
        <input {{ $attributes->class(['isian', 'num', 'galat' => $isian->adaGalat()])->merge($isian->atribut($wajib) + [
            'type'         => 'text',
            'name'         => $name,
            'inputmode'    => $desimal ? 'decimal' : 'numeric',
            'autocomplete' => 'off',
            'value'        => old($isian->kunci, $value),
        ]) }}>
        @if ($satuan !== null)
            <span class="isian-satuan-teks" aria-hidden="true">{{ $satuan }}</span>
        @endif
    </div>
</x-form.field>
