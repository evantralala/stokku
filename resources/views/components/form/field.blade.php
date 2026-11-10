{{-- Pembungkus isian: label, isian (slot), teks bantu, dan pesan error. Dipakai komponen form lain. --}}
@props(['isian', 'label', 'wajib' => false, 'bantu' => null, 'lebar' => false])

<div {{ $attributes->class(['field', 'lebar' => $lebar]) }}>
    <label for="{{ $isian->id }}">
        {{ \App\Support\Teks::judul($label) }}
        @if ($wajib)
            <span class="wajib" aria-hidden="true">*</span>
        @endif
    </label>

    {{ $slot }}

    @if ($bantu)
        <span class="bantu" id="{{ $isian->idBantu }}">{{ $bantu }}</span>
    @endif

    @if ($isian->adaGalat())
        <span class="pesan-galat" id="{{ $isian->idGalat }}">{{ $isian->pesanGalat }}</span>
    @endif
</div>