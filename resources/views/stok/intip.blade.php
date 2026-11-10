{{--
    Detail satu bahan (dibuka sebagai popup dari daftar stok; tanpa JavaScript tampil sebagai halaman biasa).
    Susunan: kartu angka (stok, minimum, pakai/hari, cukup), data bahan, status, mutasi terakhir, tombol aksi.
    Variabel dari PantauStok::intip():
      $lokasi, $rute, $bahan, $kondisi (baris PantauanStokService, null bila bahan tidak dipantau),
      $mutasi (StokMutasi terakhir), $aksi (['label', 'url'] atau null),
      $aksiStok (tombol tambah/ubah yang boleh tampil), $parameterLokasi
--}}
@extends('layouts.app')

@php
    $keBeli = fn ($qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
    $samaSatuan = (int) $bahan->satuan_beli_id === (int) $bahan->satuan_dasar_id;
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', $bahan->nama)
@section('sub', $lokasi->nama)

@section('content')
    <section class="panel intip-bahan" data-popup-isi>
        <div class="detail-isi">
            @if ($bahan->foto)
                <x-foto :src="$bahan->urlFoto()" :nama="$bahan->nama" ukuran="besar" />
            @endif

            @if ($kondisi)
                <dl class="detail-angka">
                    <div class="detail-angka-kotak">
                        <dt>Stok</dt>
                        <dd class="num lampu-angka {{ \App\Services\PantauanStokService::kelasLampu($kondisi['status']) }}">{{ $keBeli($kondisi['saldo']) }}</dd>
                        <span class="detail-angka-ket">saat ini</span>
                    </div>
                    <div class="detail-angka-kotak">
                        <dt>Minimum</dt>
                        <dd class="num">{{ $kondisi['minimum'] > 0 ? $keBeli($kondisi['minimum']) : 'Belum diatur' }}</dd>
                        <span class="detail-angka-ket">batas menipis</span>
                    </div>
                    <div class="detail-angka-kotak">
                        <dt>Pakai / Hari</dt>
                        <dd class="num">{{ $kondisi['pakai_per_hari'] > 0 ? $keBeli($kondisi['pakai_per_hari']) : '—' }}</dd>
                        <span class="detail-angka-ket">rata-rata 7 hari</span>
                    </div>
                    <div class="detail-angka-kotak">
                        <dt>Cukup</dt>
                        <dd class="num">{{ $kondisi['cukup_hari'] !== null ? \App\Support\Angka::tampil($kondisi['cukup_hari'], 1).' hari' : '—' }}</dd>
                        <span class="detail-angka-ket">perkiraan</span>
                    </div>
                </dl>
            @else
                <x-kosong gambar="kotak" kecil>Bahan ini tidak dipantau di {{ $lokasi->nama }}.</x-kosong>
            @endif

            <dl class="detail-data">
                <div><dt>Nama Bahan</dt><dd>{{ $bahan->nama }}</dd></div>
                <div><dt>Kategori</dt><dd>{{ $bahan->kategori?->nama ?? '—' }}</dd></div>
                <div><dt>Lokasi</dt><dd>{{ $lokasi->nama }}</dd></div>
                <div>
                    <dt>Satuan</dt>
                    <dd class="num">
                        {{ $samaSatuan ? $bahan->satuanDasar->nama : '1 '.$bahan->satuanBeli->nama.' = '.\App\Support\Angka::tampil($bahan->isi_per_satuan_beli).' '.$bahan->satuanDasar->nama }}
                    </dd>
                </div>
                <div><dt>Toleransi Selisih</dt><dd class="num">{{ \App\Support\Angka::tampil($bahan->toleransi_persen, 2) }}%</dd></div>
            </dl>

            <div class="detail-status">
                <span class="detail-status-label">Status:</span>
                @if ($kondisi)
                    <x-lampu-stok :status="$kondisi['status']" />
                @endif
                <x-master.status :aktif="$bahan->aktif" />
            </div>

            @if ($kondisi && $kondisi['menunggu'] != 0)
                <p class="detail-catatan stok-menunggu">{{ $kondisi['menunggu'] > 0 ? '+' : '' }}{{ $keBeli($kondisi['menunggu']) }} menunggu persetujuan</p>
            @endif
            @if ($kondisi && $kondisi['status'] === \App\Services\PantauanStokService::STATUS_MINUS)
                <p class="detail-catatan teks-kurang">Stok sistem minus. Cek dengan stock opname.</p>
            @endif

            <h3 class="detail-subjudul">Mutasi Terakhir</h3>
            @if ($mutasi->isEmpty())
                <p class="detail-catatan satuan">Belum ada pergerakan.</p>
            @else
                <div class="tabel-bungkus detail-tabel">
                    <table class="tabel-kartu tabel-ringkas">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Kejadian</th>
                                <th class="ka">Masuk</th>
                                <th class="ka">Keluar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mutasi as $m)
                                @php $masuk = (float) $m->jumlah > 0; @endphp
                                <tr @class(['baris-menunggu' => $m->status === \App\Models\StokMutasi::STATUS_MENUNGGU])>
                                    <td class="num hp-sembunyi" data-label="">{{ $m->dibuat_pada->translatedFormat('d/m/Y') }}</td>
                                    <td class="kartu-judul" data-label="">
                                        {{ $m->labelJenis() }}
                                        <div class="satuan hp-saja-teks">{{ $m->dibuat_pada->translatedFormat('j M Y') }}</div>
                                    </td>
                                    <td @class(['ka num teks-masuk', 'kartu-utama' => $masuk, 'hp-sembunyi' => ! $masuk]) data-label="">{{ $masuk ? '+'.$keBeli($m->jumlah) : '–' }}</td>
                                    <td @class(['ka num teks-kurang', 'kartu-utama' => ! $masuk, 'hp-sembunyi' => $masuk]) data-label="">{{ $masuk ? '–' : $keBeli($m->jumlah) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="simpan-bar lekat intip-aksi">
            <div class="simpan-aksi">
                <a class="btn" href="{{ route($rute.'.riwayat', [$bahan] + $parameterLokasi) }}">
                    <x-ikon nama="riwayat" />
                    Kartu Stok
                </a>
                @if ($bahan->aktif && $aksiStok['ubah'])
                    <a class="btn btn-ubah" href="{{ route($rute.'.ubah', [$bahan] + $parameterLokasi) }}" data-popup data-popup-judul="Ubah stok {{ $bahan->nama }}">
                        <x-ikon nama="pensil" />
                        Ubah Stok
                    </a>
                @endif
                @if ($aksiStok['tambah'])
                    <a class="btn {{ $aksi ? '' : 'btn-merah btn-tambah' }}" href="{{ route($rute.'.tambah', ['bahan' => $bahan->id] + $parameterLokasi) }}" data-popup data-popup-judul="Tambah stok {{ $bahan->nama }}">
                        <x-ikon nama="plus" />
                        Tambah Stok
                    </a>
                @endif
                @if ($aksi)
                    <a class="btn btn-merah" href="{{ $aksi['url'] }}">{{ $aksi['label'] }}</a>
                @endif
            </div>
        </div>
    </section>
@endsection
