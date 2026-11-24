{{--
    Baris hari libur di daftar penjualan. Parameter: $libur (PenjualanLibur), $tampilCabang (kolom Cabang),
    $kolomSisa (jumlah kolom setelah Pencatat), $ruteHapus (nama route batal, null bila tidak boleh).
--}}
<tr class="baris-libur">
    <td class="kartu-judul" data-label="Tanggal">
        <span class="nama-barang">{{ $libur->tanggal->translatedFormat($tampilCabang ? 'l, j M Y' : 'l, j M') }}</span>
    </td>
    @if ($tampilCabang)
        <td data-label="">{{ $libur->cabang->nama }}</td>
    @endif
    <td data-label="">{{ $libur->pencatat?->nama ?? '—' }}</td>
    <td class="ka kartu-utama" colspan="{{ $kolomSisa }}" data-label="">
        <span class="libur-aksi">
            <span class="badge b-pasif">Libur</span>
            @if ($ruteHapus)
                <form method="POST" action="{{ route($ruteHapus, $libur) }}"
                      data-konfirmasi="Tanggal ini kembali diingatkan sebagai penjualan yang belum dicatat."
                      data-konfirmasi-judul="Batalkan Libur?"
                      data-konfirmasi-tombol="Ya, Batalkan">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm">Batalkan</button>
                </form>
            @endif
        </span>
    </td>
</tr>
