{{--
    Tata letak halaman detail dokumen: kiri isi utama (daftar barang, form), kanan kolom samping
    (keputusan, tindakan, dokumen PDF). Kartu kepala (x-dokumen.kepala) ditaruh di atasnya. Laptop: dua kolom, kolom samping menempel saat digulir.
    HP & tablet: satu kolom, kolom samping tampil setelah isi utama.
    Slot: default (isi utama), samping (opsional).
--}}
<div {{ $attributes->class('tata-dokumen') }}>
    <div class="tata-dokumen-utama">
        {{ $slot }}
    </div>

    @if (isset($samping) && trim($samping) !== '')
        <aside class="tata-dokumen-samping" aria-label="Keterangan dokumen">
            {{ $samping }}
        </aside>
    @endif
</div>
