{{-- Kotak centang satu baris untuk x-pilih.bilah. Prop: form (id bilah), name (mis. kiriman[]), value, label (untuk pembaca layar). --}}
@props(['form', 'name', 'value', 'label'])

<label class="pilih-isi">
    <input type="checkbox" form="{{ $form }}" name="{{ $name }}" value="{{ $value }}" data-pilih-baris aria-label="Pilih {{ $label }}">
</label>
