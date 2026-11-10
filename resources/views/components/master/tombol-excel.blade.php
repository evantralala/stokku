{{--
    Tombol Impor & Ekspor Excel (gaya Majoo). Prop: master (kunci di DaftarExcelMaster), atau impor & ekspor (alamat langsung).
    label (opsional) ditambahkan di belakang teks tombol, mis. "resep" menjadi "Impor resep".
    Impor dibuka sebagai popup; ekspor langsung mengunduh isi sekarang.
--}}
@props(['master' => null, 'impor' => null, 'ekspor' => null, 'label' => null])

@php
    if ($master !== null) {
        $impor ??= \App\Services\Impor\DaftarExcelMaster::layanan($master)->bisaImpor() ? route('admin-utama.excel.unggah', $master) : null;
        $ekspor ??= route('admin-utama.excel.ekspor', $master);
    }
    $akhiran = $label ? ' '.\App\Support\Teks::judul($label) : '';
@endphp

@if ($impor)
    <a class="btn tombol-excel" href="{{ $impor }}" data-popup data-popup-judul="Impor{{ $akhiran }} dari Excel" aria-label="Impor{{ $akhiran }} dari Excel">
        <x-ikon nama="keluar" />
        <span>Impor{{ $akhiran }}</span>
    </a>
@endif
@if ($ekspor)
    <a class="btn tombol-excel" href="{{ $ekspor }}" download aria-label="Ekspor{{ $akhiran }} ke Excel">
        <x-ikon nama="masuk" />
        <span>Ekspor{{ $akhiran }}</span>
    </a>
@endif
