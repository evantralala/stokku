{{--
    Resep satu menu beserta riwayat versinya. Variabel dari ResepController@index:
      $menu, $versi (koleksi ['tanggal', 'status' => berlaku|terjadwal|lama, 'baris' => Resep[]])
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('admin-utama.menu') }}">Menu</a>
@endsection
@section('judul', 'Resep '.$menu->nama)
@section('sub', $menu->kategoriMenu->nama)

@section('aksi')
    <x-tombol-riwayat jenis="menu" :objek="$menu->id" />
    <a class="btn {{ $versi->isEmpty() ? 'btn-merah btn-tambah' : 'btn-ubah' }}" href="{{ route('admin-utama.menu.resep.ubah', $menu) }}">
        <x-ikon :nama="$versi->isEmpty() ? 'plus' : 'pensil'" />
        {{ $versi->isEmpty() ? 'Isi Resep' : 'Ubah Resep' }}
    </a>
@endsection

@section('content')
    @if ($versi->isEmpty())
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Belum ada resep. Penjualan menu ini belum memotong stok.</p>
        </div>
    @endif

    @if ($versi->where('status', 'berlaku')->isEmpty() && $versi->isNotEmpty())
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Belum ada resep yang berlaku hari ini, baru yang terjadwal.</p>
        </div>
    @endif

    {{-- Resep yang berlaku tampil lebih dulu, lalu perubahan terjadwal. --}}
    @foreach ($versi->whereIn('status', ['berlaku', 'terjadwal'])->sortBy(fn ($v) => $v['status'] === 'berlaku' ? 0 : 1) as $v)
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>
                        {{ $v['status'] === 'berlaku' ? 'Resep yang Berlaku' : 'Perubahan Terjadwal' }}
                    </h2>
                    <div class="sub">
                        1 porsi = {{ $v['baris']->count() }} bahan · mulai {{ \Illuminate\Support\Carbon::parse($v['tanggal'])->translatedFormat('j M Y') }}
                    </div>
                </div>
                <div class="kanan">
                    <span class="badge {{ $v['status'] === 'berlaku' ? 'b-aman' : 'b-jalan' }}">
                        {{ $v['status'] === 'berlaku' ? 'Berlaku' : 'Terjadwal' }}
                    </span>
                </div>
            </div>

            @include('admin-utama.menu._tabel-resep', ['baris' => $v['baris']])

            @if ($v['status'] === 'terjadwal')
                <div class="simpan-bar">
                    <div class="simpan-aksi">
                        <form method="POST" action="{{ route('admin-utama.menu.resep.hapus', [$menu, $v['tanggal']]) }}"
                              data-konfirmasi="Resep yang berlaku sekarang tetap dipakai."
                              data-konfirmasi-judul="Hapus Perubahan Terjadwal?"
                              data-konfirmasi-tombol="Ya, Hapus">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-hapus">Hapus Jadwal</button>
                        </form>
                        <a class="btn" href="{{ route('admin-utama.menu.resep.ubah', [$menu, 'tanggal' => $v['tanggal']]) }}">Ubah Jadwal</a>
                    </div>
                </div>
            @endif
        </section>
    @endforeach

    @php $lama = $versi->where('status', 'lama'); @endphp

    @if ($lama->isNotEmpty())
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Riwayat Resep</h2>
                    <div class="sub">Untuk penjualan tanggal sebelumnya. Tidak bisa diubah.</div>
                </div>
            </div>

            @foreach ($lama as $v)
                <details class="riwayat">
                    <summary>
                        Mulai {{ \Illuminate\Support\Carbon::parse($v['tanggal'])->translatedFormat('j M Y') }}
                        <span class="satuan">· {{ $v['baris']->count() }} bahan</span>
                    </summary>
                    @include('admin-utama.menu._tabel-resep', ['baris' => $v['baris']])
                </details>
            @endforeach
        </section>
    @endif
@endsection
