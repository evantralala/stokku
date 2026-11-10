{{--
    Detail kiriman untuk pengirim (Gudang Pusat, Admin Utama, atau cabang yang mengirim sendiri).
    Variabel: $kiriman (dengan detail, keCabang, pengirim, permintaan), $rute (awalan route daftar; route {$rute}.surat-jalan),
              $judulDaftar (teks tautan kembali).
    Kiri: tabel barang dikirim/diterima. Kanan: surat jalan PDF dan keterangan pengiriman.
--}}
@extends('layouts.app')

@php
    [$label, $kelas] = $kiriman->tampilanStatus();
    $keBeli = fn ($bahan, $qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
    $sudahDiterima = $kiriman->detail->contains(fn ($d) => $d->sudahDichecklist());
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute) }}">{{ $judulDaftar }}</a>
@endsection
@section('judul', $kiriman->nomor)
@section('sub', 'Ke '.$kiriman->keCabang->nama)

@section('content')
    <x-dokumen.kepala ikon="truk" nada="keluar" jenis="Kiriman" :nomor="$kiriman->nomor" :ket="$kiriman->created_at ? 'dibuat '.$kiriman->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Dari'       => $kiriman->dariCabang?->nama,
        'Tujuan'     => $kiriman->keCabang->nama,
        'Permintaan' => $kiriman->permintaan?->nomor ?? 'Tanpa permintaan',
        'Dikirim'    => $kiriman->dikirim_pada?->translatedFormat('j M, H.i'),
        'Pengirim'   => $kiriman->pengirim?->nama,
        'Diterima'   => $kiriman->sampai_pada?->translatedFormat('j M, H.i'),
    ]">
        <x-slot:status>
            <span class="badge {{ $kelas }}">{{ $label }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Barang <span class="num">{{ $kiriman->detail->count() }}</span></h2>
                    @if ($kiriman->catatan_pusat)
                        <div class="sub">{{ $kiriman->catatan_pusat }}</div>
                    @endif
                </div>
            </div>
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-barang">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="ka">Dikirim</th>
                            @if ($sudahDiterima)
                                <th class="ka">Diterima</th>
                                <th>Kondisi</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($kiriman->detail as $d)
                            @php $b = $d->bahanBaku; @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Bahan">
                                    <div class="dengan-foto">
                                        <x-foto :src="$b->urlFoto()" :nama="$b->nama" />
                                        <div class="dengan-foto-teks">
                                            <span class="nama-barang">{{ $b->nama }}</span>
                                            @if ($d->catatan_penerima)
                                                <span class="barang-ket">{{ $d->catatan_penerima }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="ka num kartu-utama" data-label="">{{ $keBeli($b, $d->qty_kirim) }}</td>
                                @if ($sudahDiterima)
                                    <td class="ka num {{ $d->sudahDichecklist() && (float) $d->selisih != 0 ? 'teks-kurang' : '' }}" data-label="Diterima">
                                        {{ $d->sudahDichecklist() ? $keBeli($b, $d->qty_terima) : '—' }}
                                    </td>
                                    <td data-label="">{{ $d->kondisi ? \App\Models\KirimanDetail::LABEL_KONDISI[$d->kondisi] : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <x-dokumen.dampak>Stok pengirim langsung berkurang saat barang dikirim. Stok tujuan bertambah sebanyak yang diterima; selisihnya tercatat otomatis.</x-dokumen.dampak>

        <x-slot:samping>
            <x-dokumen.berkas :daftar="[[
                'judul' => 'Surat jalan',
                'ket'   => 'Dibawa pengirim, ditandatangani penerima',
                'url'   => route($rute.'.surat-jalan', $kiriman),
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
