{{--
    Kirim barang untuk satu permintaan yang sudah disetujui. Variabel dari Pusat\KirimanController@create (juga Admin Utama), $rute (awalan route):
      $permintaan, $lokasi (gudang pengirim), $keputusan (Persetujuan disetujui terakhir, bisa null), $maksCatatan,
      $baris (per barang: detail, stok gudang, kirim = yang terkirim sekarang, kurang = sisa yang menyusul).
    Jumlah tidak diketik ulang: mengikuti persetujuan Admin Utama, atau sebanyak stok gudang bila kurang (KirimanService::kirimYangAda).
--}}
@extends('layouts.app')

@php
    $keBeli = fn ($bahan, $qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
    $adaKurang = $baris->contains(fn ($b) => $b['kurang'] > 0);
    $terkirim = $baris->filter(fn ($b) => $b['kirim'] > 0)->count();
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Kiriman</a>
@endsection
@section('judul', 'Kirim ke '.$permintaan->cabang->nama)
@section('sub', 'Permintaan '.$permintaan->nomor)

@section('content')
    <x-dokumen.kepala ikon="truk" nada="keluar" jenis="Siap Dikirim" :nomor="$permintaan->nomor" :ket="'diajukan '.$permintaan->diajukan_pada?->translatedFormat('d/m/Y, H.i')" :info="[
        'Dari'      => $lokasi->nama,
        'Tujuan'    => $permintaan->cabang->nama,
        'Disetujui' => $keputusan ? ($keputusan->oleh?->nama ?? 'Sistem').' · '.$keputusan->pada?->translatedFormat('j M, H.i') : null,
        'Barang'    => $baris->count().' barang',
    ]">
        <x-slot:status>
            <span @class(['badge', 'b-lewat' => $adaKurang, 'b-jalan' => ! $adaKurang])>{{ $adaKurang ? 'Stok Kurang' : 'Siap Dikirim' }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        <form class="panel" method="POST" action="{{ route($rute.'.simpan', $permintaan) }}" novalidate
              data-konfirmasi="Stok {{ $lokasi->nama }} langsung berkurang sesuai daftar ini."
              data-konfirmasi-judul="Kirim Barang Sekarang?"
              data-konfirmasi-tombol="Ya, Kirim">
            @csrf

            <div class="panel-head">
                <div>
                    <h2>Barang Dikirim <span class="num">{{ $terkirim }}</span></h2>
                    <div class="sub">Jumlah mengikuti persetujuan Admin Utama.@if ($adaKurang) Barang yang stoknya kurang dikirim sebanyak yang ada; sisanya menyusul.@endif</div>
                </div>
            </div>

            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-barang">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="ka">Disetujui</th>
                            <th class="ka">Stok Gudang</th>
                            <th class="ka">Dikirim Sekarang</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($baris as $b)
                            @php $d = $b['detail']; $bahan = $d->bahanBaku; @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Bahan">
                                    <div class="dengan-foto">
                                        <x-foto :src="$bahan->urlFoto()" :nama="$bahan->nama" />
                                        <div class="dengan-foto-teks">
                                            <span class="nama-barang">{{ $bahan->nama }}</span>
                                            @if ($d->qty_dikirim > 0)
                                                <span class="barang-ket">Sudah dikirim {{ $keBeli($bahan, $d->qty_dikirim) }}</span>
                                            @endif
                                            @if ($b['kurang'] > 0)
                                                <span class="barang-ket teks-kurang">Kurang {{ $keBeli($bahan, $b['kurang']) }}, menyusul</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="ka num" data-label="Disetujui">{{ $keBeli($bahan, $d->utang_kiriman) }}</td>
                                <td @class(['ka', 'num', 'teks-kurang' => $b['kurang'] > 0]) data-label="Stok Gudang">{{ $keBeli($bahan, max(0, $b['stok'])) }}</td>
                                <td class="ka num kartu-utama" data-label="">{{ $b['kirim'] > 0 ? $keBeli($bahan, $b['kirim']) : 'Belum bisa' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="form-grid">
                <x-form.area name="catatan_pusat" label="Catatan" :maxlength="$maksCatatan"
                             placeholder="Opsional, mis. dikirim dengan motor sore ini" />
            </div>

            @if ($terkirim > 0)
                <x-form.simpan :batal="route($rute)" label="Kirim Sekarang" />
            @else
                <div class="simpan-bar">
                    <p class="simpan-info">Stok {{ $lokasi->nama }} kosong untuk semua barang ini. Catat Barang Masuk dulu.</p>
                    <a class="btn" href="{{ route($rute) }}">Kembali</a>
                </div>
            @endif
        </form>

        <x-dokumen.dampak>Stok {{ $lokasi->nama }} langsung berkurang saat dikirim. Stok {{ $permintaan->cabang->nama }} bertambah setelah cabang mengecek barang yang tiba.</x-dokumen.dampak>

        <x-slot:samping>
            <x-dokumen.berkas :daftar="[[
                'judul' => 'Permintaan barang',
                'ket'   => 'Daftar barang yang disetujui',
                'url'   => route($rute.'.permintaan-pdf', $permintaan),
            ]]" />
            <section class="panel">
                <div class="panel-head"><h2>Setelah Dikirim</h2></div>
                <ol class="langkah-ringkas">
                    <li>Cetak Surat Jalan dari halaman kiriman, bawa bersama barang.</li>
                    <li>Cabang mencocokkan barang yang tiba lalu menyimpan penerimaan.</li>
                    <li>Bila jumlah diterima berbeda, Admin Utama diberi tahu otomatis.</li>
                </ol>
            </section>
        </x-slot:samping>
    </x-dokumen.tata>
@endsection
