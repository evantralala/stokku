{{--
    Tombol ikon sampah (merah) di kolom aksi daftar data master, di sebelah tombol Ubah.
    Data yang belum pernah dipakai dihapus permanen; yang sudah punya riwayat diarsipkan (hilang dari daftar,
    laporan dan kartu stok lama tetap ada, bisa ditambahkan lagi dengan nama yang sama).
    Prop: action (alamat DELETE), nama (nama data), label (jenis data, mis. "bahan baku").
--}}
@props(['action', 'nama', 'label'])

<form method="POST" action="{{ $action }}" class="form-ikon"
      data-konfirmasi="{{ \Illuminate\Support\Str::ucfirst($label) }} {{ $nama }} hilang dari daftar dan pilihan. Bila sudah pernah dipakai, riwayat dan laporan lamanya tetap ada, dan bisa ditambahkan lagi dengan nama yang sama."
      data-konfirmasi-judul="Hapus {{ $nama }}?"
      data-konfirmasi-tombol="Ya, Hapus">
    @csrf
    @method('DELETE')
    <button type="submit" {{ $attributes->class(['btn', 'btn-sm', 'tombol-ikon-aksi', 'tombol-ikon-hapus']) }} title="Hapus" aria-label="Hapus {{ $nama }}">
        <x-ikon nama="sampah" width="18" height="18" />
    </button>
</form>
