{{--
    Tabel daftar dokumen (barang masuk, kiriman, permintaan, dst.). Isi baris dengan <x-baris-dokumen>.
    Prop: kolom (judul kolom tambahan setelah Nomor, urut sama dengan prop sel di baris), jumlah (judul kolom jumlah, null = tanpa kolom),
          pilih (id x-pilih.bilah: tambah kolom centang di kanan; baris mengisi prop pilih-nya).
    Slot kosong: teks bila belum ada baris; tampil bila slot default kosong.
--}}
@props(['kolom' => [], 'jumlah' => 'Barang', 'pilih' => null])

@php $lebar = 2 + count($kolom) + ($jumlah !== null ? 1 : 0) + ($pilih ? 1 : 0); @endphp

<div class="tabel-bungkus">
    <table {{ $attributes->class('tabel-kartu tabel-ringkas tabel-dokumen') }}>
        <thead>
            <tr>
                <th>Nomor</th>
                @foreach ($kolom as $judul)
                    <th>{{ $judul }}</th>
                @endforeach
                @if ($jumlah !== null)
                    <th class="ka">{{ $jumlah }}</th>
                @endif
                <th>Status</th>
                @if ($pilih)
                    <th class="ka kolom-pilih"><x-pilih.semua :form="$pilih" /></th>
                @endif
            </tr>
        </thead>
        <tbody>
            @if (trim($slot) !== '')
                {{ $slot }}
            @elseif (isset($kosong))
                <tr><td colspan="{{ $lebar }}" class="kosong"><x-kosong gambar="dokumen">{{ $kosong }}</x-kosong></td></tr>
            @endif
        </tbody>
    </table>
</div>
