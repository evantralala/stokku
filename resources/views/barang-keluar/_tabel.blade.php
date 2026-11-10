{{-- Rincian barang rusak. Variabel: $detail (BarangKeluarDetail dengan bahanBaku.satuanBeli), $saldo (opsional, [bahan_baku_id => saldo dasar]). --}}
<div class="tabel-bungkus">
    <table class="tabel-kartu tabel-ringkas tabel-barang">
        <thead>
            <tr>
                <th>Bahan</th>
                <th>Alasan</th>
                <th class="ka">Jumlah</th>
                @isset($saldo)
                    <th class="ka">Stok Sekarang</th>
                @endisset
            </tr>
        </thead>
        <tbody>
            @foreach ($detail as $d)
                @php $b = $d->bahanBaku; @endphp
                <tr>
                    <td class="kartu-judul" data-label="Bahan"><span class="nama-barang">{{ $b->nama }}</span></td>
                    <td data-label="Alasan">{{ $d->penjelasan ?: '—' }}</td>
                    <td class="ka num kartu-utama" data-label="">{{ \App\Support\Angka::tampil($b->keSatuanBeli($d->qty)) }} {{ $b->satuanBeli->nama }}</td>
                    @isset($saldo)
                        <td class="ka num" data-label="Stok">{{ \App\Support\Angka::tampil($b->keSatuanBeli($saldo[$d->bahan_baku_id] ?? 0)) }} {{ $b->satuanBeli->nama }}</td>
                    @endisset
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
