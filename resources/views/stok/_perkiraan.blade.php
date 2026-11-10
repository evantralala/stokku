{{--
    Perkiraan stok setelah penjualan disimpan (PerkiraanStokService::penjualan). Dipakai di form penjualan cabang
    (diperbarui otomatis saat porsi diisi, lewat resources/js/penjualan.js) dan di cek impor penjualan.
    Variabel: $perkiraan (baris: bahan, stok, kembali, pakai, sisa, minimum, status), $judul (opsional).
    data-ringkas berisi kalimat singkat untuk dialog konfirmasi simpan.
--}}
@php
    $lampu = \App\Services\PantauanStokService::hitungPerLampu($perkiraan);
    $gawat = $lampu[\App\Services\PantauanStokService::LAMPU_MERAH] + $lampu[\App\Services\PantauanStokService::LAMPU_KUNING];
    $minus = $perkiraan->where('status', \App\Services\PantauanStokService::STATUS_MINUS)->count();
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    $ringkas = $perkiraan->isEmpty() ? '' : collect([
        $perkiraan->count().' bahan terpotong dari stok',
        $minus > 0 ? $minus.' bahan akan minus' : null,
        $gawat - $minus > 0 ? ($gawat - $minus).' bahan akan habis atau menipis' : null,
    ])->filter()->implode(', ').'.';
@endphp

<div class="perkiraan-stok" data-ringkas="{{ $ringkas }}">
    @if ($perkiraan->isEmpty())
        <p class="perkiraan-kosong satuan">Isi porsi menu yang punya resep untuk melihat perkiraan stok bahan setelah disimpan.</p>
    @else
        <details class="perkiraan-rinci" @if ($gawat > 0) open @endif>
            <summary>
                <span class="perkiraan-judul">{{ $judul ?? 'Perkiraan Stok Setelah Disimpan' }}</span>
                <span class="perkiraan-ringkas">
                    <span class="satuan">{{ $perkiraan->count() }} bahan</span>
                    <x-ringkas-lampu :jumlah="$lampu" />
                </span>
            </summary>

            <ul class="daftar-ringkas">
                @foreach ($perkiraan as $r)
                    @php $b = $r['bahan']; @endphp
                    <li class="strip-lampu {{ \App\Services\PantauanStokService::kelasLampu($r['status']) }}">
                        <div class="daftar-ringkas-isi">
                            <span class="daftar-ringkas-kiri">
                                <span class="nama-barang">{{ $b->nama }}</span>
                                <span class="satuan">
                                    Stok {{ $keBeli($b, $r['stok']) }}
                                    @if ($r['kembali'] > 0)
                                        · pemakaian lama +{{ $keBeli($b, $r['kembali']) }}
                                    @endif
                                    · dipakai {{ $keBeli($b, $r['pakai']) }}
                                </span>
                            </span>
                            <span class="daftar-ringkas-kanan">
                                <span class="num {{ $r['sisa'] < 0 ? 'teks-kurang' : '' }}">Sisa {{ $keBeli($b, $r['sisa']) }}</span>
                                <x-lampu-stok :status="$r['status']" />
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
