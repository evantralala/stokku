{{-- Daftar takaran satu versi resep. Variabel: $baris (koleksi Resep dengan bahanBaku.satuanDasar). --}}
<ul class="takaran">
    @foreach ($baris as $r)
        <li>
            <span class="nama-barang">{{ $r->bahanBaku->nama }}</span>
            <span class="takaran-nilai num">
                {{ \App\Support\Angka::tampil($r->jumlah) }} <span class="satuan">{{ $r->bahanBaku->satuanDasar->nama }}</span>
            </span>
        </li>
    @endforeach
</ul>
