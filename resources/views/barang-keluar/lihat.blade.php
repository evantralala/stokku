{{--
    Detail barang rusak untuk pencatat. Variabel: $dokumen (dengan detail, pencatat, persetujuan), $rute.
    Kiri: tabel barang. Kanan: batalkan (selama belum disetujui), keputusan Admin Utama. Atas: kartu kepala (nomor, status, info). Bawah kiri: kotak dampak.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Barang rusak</a>
@endsection
@section('judul', $dokumen->nomor)
@section('sub', $dokumen->tanggal->translatedFormat('l, j F Y'))

@section('aksi')
    @if ($dokumen->bisaDiubah())
        <a class="btn btn-ubah" href="{{ route($rute.'.ubah', $dokumen) }}">Ubah</a>
    @endif
@endsection

@section('content')
    <x-dokumen.kepala ikon="keluar" nada="keluar" jenis="Barang Rusak" :nomor="$dokumen->nomor" :ket="$dokumen->created_at ? 'dibuat '.$dokumen->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Lokasi'  => $dokumen->cabang->nama,
        'Tanggal' => $dokumen->tanggal->translatedFormat('j M Y'),
        'Dicatat' => $dokumen->pencatat->nama,
    ]">
        <x-slot:status>
            <span class="badge {{ $dokumen->kelas_status }}">{{ $dokumen->label_status }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        <section class="panel">
            <div class="panel-head"><h2>Barang <span class="num">{{ $dokumen->detail->count() }}</span></h2></div>
            @include('barang-keluar._tabel', ['detail' => $dokumen->detail])
        </section>

        <x-dokumen.dampak>Setelah disetujui Admin Utama, stok lokasi ini berkurang sesuai daftar dan tercatat di kartu stok tiap bahan sebagai barang rusak.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($dokumen->bisaDiubah())
                <section class="panel kotak-tindakan">
                    <form class="kotak-tindakan-isi" method="POST" action="{{ route($rute.'.batal', $dokumen) }}"
                          data-konfirmasi="{{ $dokumen->nomor }} dibatalkan. Stok tidak berubah."
                          data-konfirmasi-judul="Batalkan Dokumen Ini?"
                          data-konfirmasi-tombol="Ya, Batalkan">
                        @csrf
                        @method('PATCH')
                        <p>Belum disetujui Admin Utama, masih bisa dibatalkan.</p>
                        <button type="submit" class="btn btn-bahaya">Batalkan</button>
                    </form>
                </section>
            @endif

            @if ($dokumen->persetujuan->isNotEmpty())
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan</h2></div>
                    <x-riwayat-keputusan :riwayat="$dokumen->persetujuan" />
                </section>
            @endif

            <x-dokumen.berkas :daftar="[[
                'judul' => 'Berita acara barang rusak',
                'ket'   => 'Untuk ditandatangani pelapor dan Admin Utama',
                'url'   => route($rute.'.pdf', $dokumen),
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
