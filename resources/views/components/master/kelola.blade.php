{{--
    Panel status di halaman ubah: aktifkan/nonaktifkan dan hapus. Hapus selalu ada (kecuali akun sendiri):
    belum pernah dipakai = hapus permanen; sudah dipakai = diarsipkan, riwayat tetap ada.
    Prop: rute, item (model), label (mis. "satuan"), bisaDihapus (belum pernah dipakai),
          dampak (kalimat akibat menonaktifkan, mis. "tidak muncul di pilihan barang masuk").
--}}
@props(['rute', 'item', 'label', 'bisaDihapus' => false, 'dampak'])

@php $nama = $item->namaTampilan(); @endphp

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ \App\Support\Teks::judul('Status '.$label) }}</h2>
            <div class="sub">
                {{ $item->aktif ? 'Bila dinonaktifkan: '.$dampak.'.' : \Illuminate\Support\Str::ucfirst($dampak).'.' }}
            </div>
        </div>
        <div class="kanan">
            <x-master.status :aktif="$item->aktif" />
        </div>
    </div>

    <div class="simpan-bar">
        @unless ($bisaDihapus)
            <span class="simpan-info">Sudah pernah dipakai: bila dihapus, riwayat dan laporan lamanya tetap ada.</span>
        @endunless

        <div class="simpan-aksi">
            @unless ($item instanceof \App\Models\User && $item->is(auth()->user()))
                <form method="POST" action="{{ route($rute.'.hapus', $item) }}"
                      data-konfirmasi="{{ $bisaDihapus
                          ? \Illuminate\Support\Str::ucfirst($label).' '.$nama.' belum pernah dipakai, jadi akan dihapus permanen.'
                          : \Illuminate\Support\Str::ucfirst($label).' '.$nama.' hilang dari daftar dan pilihan. Riwayat dan laporan lamanya tetap ada, dan bisa ditambahkan lagi dengan nama yang sama.' }}"
                      data-konfirmasi-judul="{{ \App\Support\Teks::judul('Hapus '.$label) }}?"
                      data-konfirmasi-tombol="Ya, Hapus">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-hapus"><x-ikon nama="sampah" width="18" height="18" />Hapus</button>
                </form>
            @endunless

            <form method="POST" action="{{ route($rute.'.aktif', $item) }}"
                  @if ($item->aktif)
                      data-konfirmasi="{{ \Illuminate\Support\Str::ucfirst($label) }} {{ $nama }} {{ $dampak }} sampai diaktifkan lagi."
                      data-konfirmasi-judul="{{ \App\Support\Teks::judul('Nonaktifkan '.$label) }}?"
                      data-konfirmasi-tombol="Ya, Nonaktifkan"
                  @endif>
                @csrf
                @method('PATCH')
                <input type="hidden" name="aktif" value="{{ $item->aktif ? 0 : 1 }}">
                <button type="submit" class="btn">{{ $item->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button>
            </form>
        </div>
    </div>
</section>