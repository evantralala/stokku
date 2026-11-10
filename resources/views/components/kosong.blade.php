{{--
    Keadaan kosong bergambar. Slot = kalimat utama (boleh berisi tautan).
    Prop: gambar (kotak | selesai | cari | dokumen | grafik | lonceng), ket (kalimat kedua, opsional),
          kecil (gambar lebih kecil, untuk panel dashboard dan daftar ringkas).
    Di dalam tabel: <td colspan="…" class="kosong"><x-kosong …>…</x-kosong></td>.
--}}
@props(['gambar' => 'kotak', 'ket' => null, 'kecil' => false])

<div {{ $attributes->class(['kosong', 'kosong-gambar', 'kosong-kecil' => $kecil]) }}>
    <x-dynamic-component :component="'ilustrasi.'.$gambar" class="kosong-ilustrasi" />
    <p class="kosong-judul">{{ $slot }}</p>
    @if ($ket)
        <p class="kosong-ket">{{ $ket }}</p>
    @endif
</div>
