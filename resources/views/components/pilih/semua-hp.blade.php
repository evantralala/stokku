{{--
    Baris "Pilih Semua" untuk HP, tempat kepala tabel (dan x-pilih.semua) tersembunyi karena tabel tampil sebagai kartu.
    Prop: form (id bilah), label (teks di samping kotak).
--}}
@props(['form', 'label' => 'Pilih Semua'])

<label class="pilih-semua-hp">
    <span>{{ $label }}</span>
    <input type="checkbox" data-pilih-semua="{{ $form }}">
</label>
