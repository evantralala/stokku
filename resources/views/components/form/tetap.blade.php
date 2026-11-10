{{--
    Nilai yang tidak bisa diubah, ditampilkan seperti isian.
    Prop: label, nilai (teks tampilan), bantu, name + value (opsional, dikirim sebagai input tersembunyi), lebar.
--}}
@props(['label', 'nilai', 'bantu' => null, 'name' => null, 'value' => null, 'lebar' => false])

<div {{ $attributes->class(['field', 'lebar' => $lebar]) }}>
    <span class="label-tetap">{{ \App\Support\Teks::judul($label) }}</span>
    <div class="nilai-tetap">{{ $nilai }}</div>

    @if ($bantu)
        <span class="bantu">{{ $bantu }}</span>
    @endif

    @if ($name)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endif
</div>