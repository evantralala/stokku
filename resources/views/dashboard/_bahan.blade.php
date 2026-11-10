{{--
    Daftar singkat bahan di dashboard.
    Variabel: $daftar (baris PantauanStokService), $ruteStok (URL daftar stok), $ruteRiwayat (nama route riwayat).
--}}
<ul class="daftar-ringkas">
    @forelse ($daftar as $r)
        @php $b = $r['bahan']; @endphp
        <li class="strip-lampu {{ \App\Services\PantauanStokService::kelasLampu($r['status']) }}">
            <a href="{{ route($ruteRiwayat, $b) }}">
                <span class="daftar-ringkas-kiri">
                    <span class="nama-barang">{{ $b->nama }}</span>
                    <span class="satuan">
                        @if ($r['minimum'] > 0)
                            Min. {{ \App\Support\Angka::tampil($b->keSatuanBeli($r['minimum'])) }} {{ $b->satuanBeli->nama }}
                        @endif
                    </span>
                </span>
                <span class="daftar-ringkas-kanan">
                    <span class="num {{ $r['saldo'] < 0 ? 'teks-kurang' : '' }}">{{ \App\Support\Angka::tampil($b->keSatuanBeli($r['saldo'])) }} {{ $b->satuanBeli->nama }}</span>
                    <x-lampu-stok :status="$r['status']" />
                </span>
            </a>
        </li>
    @empty
        <li><x-kosong gambar="selesai" kecil>Semua stok aman.</x-kosong></li>
    @endforelse
</ul>
<div class="panel-kaki"><a class="link" href="{{ $ruteStok }}">Lihat semua stok</a></div>
