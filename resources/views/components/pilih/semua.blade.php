{{-- Kotak centang "pilih semua" di kepala tabel untuk x-pilih.bilah. Prop: form (id bilah), label. --}}
@props(['form', 'label' => 'semua'])

<label class="pilih-isi">
    <input type="checkbox" data-pilih-semua="{{ $form }}" aria-label="Pilih {{ $label }}">
</label>
