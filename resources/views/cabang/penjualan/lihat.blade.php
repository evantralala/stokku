{{--
    Detail penjualan harian (Admin Cabang, atau Admin Utama lewat admin-utama.penjualan). Variabel dari PenjualanController@show:
      $rute (awalan route), $bisaUbah, $riwayat (LogPerubahan penjualan.ubah, terbaru dulu), $penjualan, $detail,
      $pemakaian (BarangKeluarDetail).
    Kiri: porsi terjual per kategori menu dan pemakaian bahan. Kanan: riwayat perubahan.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">{{ $rute === 'cabang.penjualan' ? 'Penjualan harian' : 'Catatan Penjualan' }}</a>
@endsection
@section('judul', 'Penjualan '.$penjualan->tanggal->translatedFormat('j M Y'))
@section('sub', ($rute === 'cabang.penjualan' ? '' : $penjualan->cabang->nama.' · ').'Dicatat oleh '.$penjualan->namaPencatat())

@section('aksi')
    @if ($bisaUbah)
        @if ($rute === 'cabang.penjualan')
            <a class="btn tombol-ikon-hp" href="{{ route('cabang.penjualan.impor', ['penjualan' => $penjualan->id]) }}" data-popup aria-label="Ubah lewat Excel">
                <x-ikon nama="keluar" />
                <span>Ubah Lewat Excel</span>
            </a>
        @endif
        <a class="btn btn-ubah" href="{{ route($rute.'.ubah', $penjualan) }}">Ubah</a>
    @endif
@endsection

@section('content')
    <x-dokumen.kepala ikon="mangkuk" nada="keluar" jenis="Penjualan Harian" :nomor="'Penjualan '.$penjualan->tanggal->translatedFormat('j M Y')" :ket="$penjualan->created_at ? 'dibuat '.$penjualan->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Tanggal'            => $penjualan->tanggal->translatedFormat('l, j M Y'),
        'Dicatat oleh'       => $penjualan->namaPencatat(),
        'Terakhir diubah'    => ($u = $riwayat->first()) ? ($u->oleh?->nama ?? 'Sistem').' · '.$u->created_at->translatedFormat('l, j M Y, H.i') : null,
        'Total porsi'        => \App\Support\Angka::tampil($detail->sum('porsi')).' porsi',
        'Bisa Diubah sampai' => $rute === 'cabang.penjualan' && $penjualan->bisaDiubah() ? $penjualan->batasUbah()->translatedFormat('l, j M').' pukul 23.59' : null,
    ]" />

    <x-dokumen.tata>
        <section class="panel">
            <div class="panel-head"><h2>Porsi Terjual <span class="num">{{ \App\Support\Angka::tampil($detail->sum('porsi')) }}</span></h2></div>
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-barang">
                    <thead>
                        <tr>
                            <th>Menu</th>
                            <th class="ka">Porsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $kategoriSebelumnya = null; @endphp
                        @foreach ($detail as $d)
                            @if ($d->menu->kategoriMenu->nama !== $kategoriSebelumnya)
                                <tr class="subjudul-baris">
                                    <td colspan="2" data-label="">{{ $kategoriSebelumnya = $d->menu->kategoriMenu->nama }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="kartu-judul" data-label="Menu">
                                    <div class="dengan-foto">
                                        <x-foto :src="$d->menu->urlFoto()" :nama="$d->menu->nama" />
                                        <div class="dengan-foto-teks">
                                            <span class="nama-barang">{{ $d->menu->nama }}</span>
                                            @if (empty($d->takaran_resep_json))
                                                <span class="barang-ket"><span class="badge b-awas">Tanpa Resep</span></span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="ka num kartu-utama" data-label="">{{ $d->porsi }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Pemakaian Bahan</h2>
                    <div class="sub">Sudah dipotong dari stok</div>
                </div>
            </div>
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-barang">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="ka">Terpakai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pemakaian as $p)
                            <tr>
                                <td class="kartu-judul" data-label="Bahan"><span class="nama-barang">{{ $p->bahanBaku->nama }}</span></td>
                                <td class="ka num kartu-utama" data-label="">{{ \App\Support\Angka::tampil($p->qty) }} {{ $p->bahanBaku->satuanDasar->nama }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="kosong"><x-kosong gambar="kotak">Menu yang terjual belum punya resep.</x-kosong></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <x-dokumen.dampak>Penjualan memotong stok bahan sesuai resep yang berlaku di tanggal itu dan tercatat di kartu stok sebagai pemakaian dapur.</x-dokumen.dampak>

        <x-slot:samping>
            <section class="panel riwayat-penjualan">
                <div class="panel-head"><h2>Riwayat Perubahan</h2></div>
                <ul class="daftar-ringkas">
                    @forelse ($riwayat as $log)
                        <li>
                            <div class="daftar-ringkas-isi">
                                <span class="daftar-ringkas-kiri">
                                    <span class="nama-barang">{{ $log->oleh?->nama ?? 'Sistem' }}</span>
                                    <span class="satuan">{{ $log->created_at->translatedFormat('l, j M Y, H.i') }}</span>
                                    @foreach ($log->baris_rincian as $r)
                                        <span class="satuan">{{ $r['label'] }}: {{ $r['lama'] !== null ? $r['lama'].' → ' : '' }}{{ $r['baru'] ?? '0 porsi' }}</span>
                                    @endforeach
                                </span>
                            </div>
                        </li>
                    @empty
                        <li><x-kosong gambar="dokumen" kecil>Belum pernah diubah.</x-kosong></li>
                    @endforelse
                </ul>
            </section>
        </x-slot:samping>
    </x-dokumen.tata>
@endsection
