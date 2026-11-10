{{--
    Kartu stok satu bahan di satu lokasi: kartu angka, kondisi, lalu semua mutasi
    (masuk dan keluar di kolom terpisah, saldo setelahnya). Variabel dari PantauStok::riwayat():
      $lokasi, $rute, $bahan, $mutasi (paginator StokMutasi), $saldoSetelah ([mutasi id => saldo]),
      $saldoSekarang, $kondisi (baris PantauanStokService, null bila bahan tidak dipantau),
      $dokumen ([mutasi id => ['nomor', 'url']]), $parameterLokasi
--}}
@extends('layouts.app')

@php
    $keBeli = fn ($qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', $bahan->nama)
@section('sub', 'Kartu stok · '.$lokasi->nama.' · stok sekarang '.$keBeli($saldoSekarang))

@section('aksi')
    @if ($bahan->aktif && $aksiStok['ubah'])
        <a class="btn" href="{{ route($rute.'.ubah', [$bahan] + $parameterLokasi) }}" data-popup>
            <x-ikon nama="pensil" />
            Ubah Stok
        </a>
    @endif
    @if ($aksiStok['tambah'])
        <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah', ['bahan' => $bahan->id] + $parameterLokasi) }}" data-popup>
            <x-ikon nama="plus" />
            Tambah Stok
        </a>
    @endif
@endsection

@section('content')
    <div class="kartu-grid">
        <x-kartu-angka ikon="kotak" label="Stok Saat Ini" :angka="$keBeli($saldoSekarang)"
                       :nada="$kondisi ? ['merah' => 'bahaya', 'kuning' => 'perhatian', 'hijau' => 'baik'][$kondisi['lampu']] : 'biasa'"
                       :ket="$kondisi ? \App\Services\PantauanStokService::teks($kondisi['status']) : null" />
        <x-kartu-angka ikon="peringatan" label="Minimum" :angka="$kondisi && $kondisi['minimum'] > 0 ? $keBeli($kondisi['minimum']) : '—'"
                       ket="batas menipis" />
        <x-kartu-angka ikon="grafik" label="Pakai / Hari" :angka="$kondisi && $kondisi['pakai_per_hari'] > 0 ? $keBeli($kondisi['pakai_per_hari']) : '—'"
                       ket="rata-rata 7 hari" />
        <x-kartu-angka ikon="kalender" label="Cukup untuk" :angka="$kondisi && $kondisi['cukup_hari'] !== null ? \App\Support\Angka::tampil($kondisi['cukup_hari'], 1).' hari' : '—'"
                       ket="perkiraan" />
    </div>

    @if ($kondisi)
        <section class="panel">
            <div class="kondisi-bahan strip-lampu {{ \App\Services\PantauanStokService::kelasLampu($kondisi['status']) }}">
                <x-lampu-stok :status="$kondisi['status']" />
                <span class="satuan">
                    {{ $kondisi['minimum'] > 0 ? 'Min. '.$keBeli($kondisi['minimum']) : 'Min. belum diatur' }}
                </span>
            </div>
        </section>
    @endif

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2><x-ikon-judul nama="riwayat" />Mutasi Stok</h2>
                <div class="sub">{{ $mutasi->total() }} catatan, terbaru di atas</div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-kartu-stok">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kejadian</th>
                        <th>No. Dokumen</th>
                        <th class="ka">Masuk</th>
                        <th class="ka">Keluar</th>
                        <th class="ka">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mutasi as $m)
                        @php $doc = $dokumen[$m->id] ?? ['nomor' => '—', 'url' => null]; @endphp
                        <tr class="{{ $m->status === \App\Models\StokMutasi::STATUS_MENUNGGU ? 'baris-menunggu' : '' }}">
                            <td class="kartu-judul" data-label="Waktu">
                                <span class="nama-barang">{{ $m->dibuat_pada->translatedFormat('j M Y, H.i') }}</span>
                                <div class="satuan">{{ $m->pembuat?->nama ?? 'Sistem' }}</div>
                            </td>
                            <td data-label="Kejadian">
                                {{ $m->labelJenis() }}
                                @if ($m->status === \App\Models\StokMutasi::STATUS_MENUNGGU)
                                    <span class="badge b-tunggu">Menunggu Persetujuan</span>
                                @endif
                            </td>
                            <td class="num" data-label="Dokumen">
                                @if ($doc['url'])
                                    <a class="link" href="{{ $doc['url'] }}">{{ $doc['nomor'] }}</a>
                                @else
                                    {{ $doc['nomor'] }}
                                @endif
                            </td>
                            @php $masuk = (float) $m->jumlah > 0; @endphp
                            <td @class(['ka num teks-masuk', 'hp-sembunyi' => ! $masuk]) data-label="Masuk">{{ $masuk ? '+'.$keBeli($m->jumlah) : '–' }}</td>
                            <td @class(['ka num teks-kurang', 'hp-sembunyi' => $masuk]) data-label="Keluar">{{ $masuk ? '–' : $keBeli($m->jumlah) }}</td>
                            <td class="ka num nama-barang" data-label="Saldo">
                                {{ isset($saldoSetelah[$m->id]) ? $keBeli($saldoSetelah[$m->id]) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="kosong"><x-kosong gambar="grafik">Belum ada pergerakan stok untuk bahan ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$mutasi" />
    </section>
@endsection
