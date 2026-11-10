{{--
    Bilah bawah "pilih banyak" untuk daftar bercentang (resources/js/pilih-banyak.js). Muncul setelah minimal satu baris dicentang.
    Kotak centang baris (x-pilih.kotak) memakai atribut form="{id}", jadi bilah ini boleh ditaruh di luar tabel dan tabel boleh berisi form lain.
    Prop: id, action (route POST), tombol (teks tombol kirim), hijau (warna tombol), konfirmasi, judulKonfirmasi, tombolKonfirmasi.
--}}
@props(['id', 'action', 'tombol', 'hijau' => false, 'konfirmasi' => null, 'judulKonfirmasi' => null, 'tombolKonfirmasi' => null])

<form id="{{ $id }}" method="POST" action="{{ $action }}" {{ $attributes->class('simpan-bar lekat bilah-pilih') }} data-pilih-banyak hidden
      @if ($konfirmasi) data-konfirmasi="{{ $konfirmasi }}" data-konfirmasi-judul="{{ $judulKonfirmasi }}" data-konfirmasi-tombol="{{ $tombolKonfirmasi }}" @endif>
    @csrf
    <span class="bilah-pilih-teks" data-pilih-teks aria-live="polite">0 dipilih</span>
    <div class="bilah-pilih-aksi">
        <button type="button" class="btn" data-pilih-batal>Batal Pilih</button>
        <button type="submit" class="btn {{ $hijau ? 'btn-hijau' : 'btn-merah' }}">
            <x-ikon nama="ceklis" />
            {{ $tombol }}
        </button>
    </div>
</form>
