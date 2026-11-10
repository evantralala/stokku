{{--
    Baris tabel saat daftar kosong.
    Prop: kolom (jumlah kolom tabel), adaData (apakah ada data sama sekali), rute, label (mis. "satuan"),
    popup (ukuran popup seperti tombol Tambah di kepala halaman: '' pendek atau 'panjang').
    Tautan tambah membuka popup yang sama dengan tombol Tambah di kepala halaman (ukuran popup ditentukan form-nya).
--}}
@props(['kolom', 'adaData', 'rute', 'label', 'popup' => ''])

<tr>
    <td colspan="{{ $kolom }}" class="kosong">
        @if ($adaData)
            <x-kosong gambar="cari">
                Tidak ketemu.
                <a class="link" href="{{ route($rute) }}">Hapus filter</a>
            </x-kosong>
        @else
            <x-kosong gambar="kotak">
                Belum ada {{ $label }}.
                <a class="link" href="{{ route($rute.'.tambah') }}" data-popup="{{ $popup }}">+ {{ \App\Support\Teks::judul('Tambah '.$label) }}</a>
            </x-kosong>
        @endif
    </td>
</tr>