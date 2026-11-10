{{--
    Detail satu permintaan. Variabel: $permintaan (dengan detail.bahanBaku.satuanBeli/satuanDasar, pengaju).
    Kiri: tabel barang. Kanan: batalkan (selama belum diputuskan), PDF permintaan (semua barang dan statusnya). Atas: kartu kepala (nomor, status, info). Bawah kiri: kotak dampak.
--}}
@extends('layouts.app')

@php
    [$labelStatus, $kelasStatus] = $permintaan->tampilanStatus();
    $keBeli = fn ($d, $qty) => \App\Support\Angka::tampil($d->bahanBaku->keSatuanBeli($qty)).' '.$d->bahanBaku->satuanBeli->nama;
    $tautanKiriman = $permintaan->kiriman
        ->map(fn ($k) => '<a class="link" href="'.e(route('cabang.terima-barang.lihat', $k)).'">'.e($k->nomor).'</a>')
        ->implode(', ');
@endphp

@section('crumb')
    <a class="link" href="{{ route('cabang.permintaan') }}">Permintaan</a>
@endsection
@section('judul', $permintaan->nomor)
@section('sub', 'Diajukan '.$permintaan->diajukan_pada?->translatedFormat('j M, H.i'))

@section('content')
    <x-dokumen.kepala ikon="panah-kiri" nada="netral" jenis="Permintaan Barang" :nomor="$permintaan->nomor" :ket="$permintaan->diajukan_pada ? 'dibuat '.$permintaan->diajukan_pada->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Cabang'     => $permintaan->cabang->nama,
        'Diajukan'   => ($permintaan->otomatis() ? 'Otomatis' : $permintaan->pengaju?->nama).' · '.$permintaan->diajukan_pada?->translatedFormat('j M, H.i'),
        'Kiriman'    => $tautanKiriman !== '' ? new \Illuminate\Support\HtmlString($tautanKiriman) : null,
    ]">
        <x-slot:status>
            <span class="badge {{ $kelasStatus }}">{{ $labelStatus }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    @modul('tolak_otomatis')
        @if ($permintaan->status === \App\Models\Permintaan::STATUS_MENUNGGU && $permintaan->batas_keputusan)
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="peringatan" />
                <p>Bila belum diputuskan Admin Utama sampai {{ $permintaan->batas_keputusan->translatedFormat('j M, H.i') }}, permintaan ditolak otomatis.</p>
            </div>
        @endif
    @endmodul

    <x-dokumen.tata>
        <section class="panel">
            <div class="panel-head"><h2>Barang <span class="num">{{ $permintaan->detail->count() }}</span></h2></div>
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-barang">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="ka">Stok Saat Diajukan</th>
                            <th class="ka">Diminta</th>
                            <th class="ka">Disetujui</th>
                            <th class="ka">Dikirim</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($permintaan->detail as $d)
                            @php [$labelBaris, $kelasBaris] = $d->tampilanStatus(); @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Bahan">
                                    <div class="dengan-foto">
                                        <x-foto :src="$d->bahanBaku->urlFoto()" :nama="$d->bahanBaku->nama" />
                                        <div class="dengan-foto-teks">
                                            <span class="nama-barang">{{ $d->bahanBaku->nama }}</span>
                                            @if ($d->alasan)
                                                <span class="barang-ket">{{ $d->alasan }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="ka num" data-label="Stok saat diajukan">{{ $keBeli($d, $d->sisa_saat_itu) }}</td>
                                <td class="ka num kartu-utama" data-label="">{{ $keBeli($d, $d->qty_diminta) }}</td>
                                <td class="ka num {{ $d->qty_disetujui === null ? 'hp-sembunyi' : '' }}" data-label="Disetujui">
                                    {{ $d->qty_disetujui !== null ? $keBeli($d, $d->qty_disetujui) : '—' }}
                                </td>
                                <td class="ka num {{ $d->qty_disetujui === null ? 'hp-sembunyi' : '' }}" data-label="Dikirim">
                                    {{ $d->qty_disetujui !== null ? $keBeli($d, $d->qty_dikirim) : '—' }}
                                </td>
                                <td data-label=""><span class="badge {{ $kelasBaris }}">{{ $labelBaris }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <x-dokumen.dampak>Permintaan tidak mengubah stok. Stok berpindah saat Gudang Pusat mengirim barang dan cabang menerimanya.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($permintaan->bisaDibatalkan() && app(\App\Services\KontrolSistemService::class)->boleh(auth()->user(), 'permintaan.catat'))
                <section class="panel kotak-tindakan">
                    <form class="kotak-tindakan-isi" method="POST" action="{{ route('cabang.permintaan.batal', $permintaan) }}"
                          data-konfirmasi="{{ $permintaan->nomor }} dibatalkan dan tidak diproses gudang."
                          data-konfirmasi-judul="Batalkan Permintaan?"
                          data-konfirmasi-tombol="Ya, Batalkan">
                        @csrf
                        @method('PATCH')
                        <p>Belum diputuskan Admin Utama, masih bisa dibatalkan.</p>
                        <button type="submit" class="btn btn-bahaya">Batalkan</button>
                    </form>
                </section>
            @endif

            <x-dokumen.berkas :daftar="[[
                'judul' => 'Permintaan barang',
                'ket'   => 'Semua barang beserta statusnya',
                'url'   => route('cabang.permintaan.pdf', $permintaan),
            ], [
                'judul' => 'Permintaan barang',
                'ket'   => 'Excel, bisa diunggah lagi untuk minta ulang',
                'url'   => route('cabang.permintaan.excel', $permintaan),
                'jenis' => 'XLS',
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
