{{--
    Baris tombol simpan yang menempel di bawah layar.
    Prop: batal (URL tombol Batal; di dalam popup tombol ini menutup popup), label (teks tombol simpan), tambahLagi (tampilkan "Simpan & tambah lagi").
--}}
@props(['batal', 'label' => 'Simpan', 'tambahLagi' => false])

<div class="simpan-bar lekat">
    <div class="simpan-aksi">
        <a class="btn" href="{{ $batal }}" data-popup-batal>Batal</a>

        @if ($tambahLagi)
            <button type="submit" class="btn" name="tambah_lagi" value="1">Simpan & Tambah Lagi</button>
        @endif

        <button type="submit" class="btn btn-merah">
            <x-ikon nama="ceklis" />
            {{ \App\Support\Teks::judul($label) }}
        </button>
    </div>
</div>