{{-- Satu bahan di persetujuan opname (baris tabel). Variabel: $d (OpnameDetail dengan bahanBaku), $keBeli (fn bahan, qty dasar). --}}
@php
    $b = $d->bahanBaku;
    $selisih = (float) $d->qty_fisik - (float) $d->qty_sistem;
@endphp
<tr @class(['baris-lewat' => $d->lewatToleransi()])>
    <td class="kartu-judul" data-label="Bahan">
        <div class="dengan-foto">
            <x-foto :src="$b->urlFoto()" :nama="$b->nama" />
            <div class="dengan-foto-teks">
                <span class="nama-barang">{{ $b->nama }}</span>
                @if ($d->catatan)
                    <span class="barang-ket">{{ $d->catatan }}</span>
                @endif
            </div>
        </div>
    </td>
    <td class="ka num" data-label="Sistem">{{ $keBeli($b, $d->qty_sistem) }}</td>
    <td class="ka num" data-label="Fisik">{{ $keBeli($b, $d->qty_fisik) }}</td>
    <td class="ka num kartu-utama {{ $selisih < 0 ? 'teks-kurang' : ($selisih > 0 ? 'teks-masuk' : '') }}" data-label="">
        {{ $selisih > 0 ? '+' : '' }}{{ $selisih == 0 ? '0' : $keBeli($b, $selisih) }}
    </td>
    <td data-label="">
        @if ($selisih == 0)
            <span class="badge b-cocok">Sesuai</span>
        @elseif ($d->lewatToleransi())
            <span class="badge b-selisih">Lewat {{ \App\Support\Angka::tampil($b->toleransi_persen, 1) }}%</span>
        @else
            <span class="badge b-aman">Wajar · {{ \App\Support\Angka::tampil($d->persenSelisih(), 1) }}%</span>
        @endif
    </td>
</tr>
