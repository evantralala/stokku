{{--
    Dashboard Admin Cabang: banner sapaan, kartu angka, penjualan hari ini, tombol cepat, grafik pergerakan + donat kondisi stok,
    segera habis.
    Variabel dari Cabang\DashboardController:
      $lokasi (null bila akun belum terhubung ke cabang aktif), $penjualanHariIni (catatan hari operasional), $hariOperasional,
      $hariDiarsipkan (penjualan hari operasional sudah ikut tutup buku, jadi tidak bisa dicatat lagi),
      $penjualanTerlewat (tanggal sebelumnya yang belum dicatat, paling lama 7 hari), $permintaanMenunggu, $jumlahUsulan,
      $opnameBerjalan, $jumlahPerLampu, $segeraHabis, $bolehMinta; $jumlah dan $pengguna dari LayoutComposer.
--}}
@extends('layouts.app')

@section('judul', 'Dashboard')
@section('segar', 'ya')
@section('sub', ($lokasi?->nama ? $lokasi->nama.' · ' : '').now()->translatedFormat('l, j F Y'))

@section('content')
    @if (! $lokasi)
        <div class="kilat kilat-galat" role="alert">
            <x-ikon nama="peringatan" />
            <p>Akun Anda belum terhubung ke cabang yang aktif. Hubungi Admin Utama.</p>
        </div>
    @else
        @if ($opnameBerjalan)
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="peringatan" />
                <p>
                    Stok cabang sedang dikunci untuk stock opname {{ $opnameBerjalan->nomor }}.
                    @boleh('opname.lihat')
                        <a class="link" href="{{ route('cabang.opname.lihat', $opnameBerjalan) }}">Buka opname</a>
                    @endboleh
                </p>
            </div>
        @endif

        @php
            $merah = $jumlahPerLampu[\App\Services\PantauanStokService::LAMPU_MERAH];
            $kuning = $jumlahPerLampu[\App\Services\PantauanStokService::LAMPU_KUNING];
            $porsi = $penjualanHariIni ? \App\Support\Angka::tampil($penjualanHariIni->total_porsi ?? 0) : null;
            $kontrol = app(\App\Services\KontrolSistemService::class);
            $labelHari = $hariOperasional->isToday() ? 'Hari Ini' : $hariOperasional->translatedFormat('l, j M');
            $diarsipkan = ! $penjualanHariIni && $hariDiarsipkan;
            $selesai = $penjualanHariIni || $diarsipkan;
        @endphp

        @if ($penjualanTerlewat !== [])
            <div class="kilat kilat-galat penjualan-terlewat" role="alert">
                <x-ikon nama="peringatan" />
                <p>
                    Penjualan belum dicatat:
                    @foreach ($penjualanTerlewat as $t)
                        <a class="link" href="{{ route('cabang.penjualan.catat', ['tanggal' => $t->toDateString()]) }}" data-popup="panjang">{{ $t->translatedFormat('D, j M') }}</a>{{ $loop->last ? '.' : ',' }}
                    @endforeach
                    Masih bisa dicatat sampai {{ \App\Models\PenjualanHarian::BATAS_MUNDUR_HARI }} hari ke belakang.
                </p>
            </div>
        @endif

        @include('dashboard._sapaan', [
            'ruteCari' => \App\View\MenuSidebar::ruteCari($pengguna),
            'ringkas'  => $lokasi->nama.' · penjualan '.mb_strtolower($labelHari).' '.($porsi !== null ? $porsi.' porsi' : ($diarsipkan ? 'sudah diarsipkan' : 'belum dicatat'))
                .' · '.$merah.' bahan habis, '.$kuning.' menipis.',
        ])

        <div class="kartu-grid">
            <x-kartu-angka ikon="mangkuk" :label="'Penjualan '.$labelHari" :angka="$porsi ?? ($diarsipkan ? 'Arsip' : '—')"
                           :tautan="$penjualanHariIni ? route('cabang.penjualan.lihat', $penjualanHariIni) : route('cabang.penjualan')"
                           :nada="$selesai ? 'baik' : 'perhatian'" :ket="$porsi !== null ? 'porsi tercatat' : ($diarsipkan ? 'ikut tutup buku' : 'belum dicatat')" />
            <x-kartu-angka warna="biru" ikon="masuk" label="Kiriman Belum Diterima" :angka="$jumlah['kiriman_belum_dichecklist']"
                           :tautan="$kontrol->boleh($pengguna, 'terima-barang.lihat') ? route('cabang.terima-barang') : null"
                           :nada="$jumlah['kiriman_belum_dichecklist'] > 0 ? 'perhatian' : 'biasa'" ket="perlu dihitung saat tiba" />
            <x-kartu-angka warna="ungu" ikon="panah-kiri" label="Permintaan Menunggu" :angka="$permintaanMenunggu"
                           :tautan="$kontrol->boleh($pengguna, 'permintaan.lihat') ? route('cabang.permintaan') : null"
                           :ket="$jumlahUsulan > 0 ? $jumlahUsulan.' bahan perlu diminta' : 'menunggu Admin Utama'" />
            <x-kartu-angka ikon="kotak" label="Stok Habis / Menipis" :angka="$merah.' / '.$kuning" :tautan="route('cabang.stok')"
                           :nada="$merah > 0 ? 'bahaya' : ($kuning > 0 ? 'perhatian' : 'baik')"
                           :ket="$merah + $kuning > 0 ? 'centang lalu minta di Stok' : 'Semua aman'" />
        </div>

        <section class="panel tugas-utama {{ $selesai ? 'tugas-selesai' : '' }}">
            <div class="panel-head">
                <div>
                    <h2><x-ikon-judul nama="mangkuk" />Penjualan {{ $labelHari }}</h2>
                    <div class="sub">
                        {{ $penjualanHariIni
                            ? 'Sudah dicatat: '.\App\Support\Angka::tampil($penjualanHariIni->total_porsi ?? 0).' porsi'
                            : ($diarsipkan ? 'Sudah diarsipkan saat tutup buku' : 'Belum Dicatat') }}
                    </div>
                </div>
                @unless ($diarsipkan)
                <div class="kanan">
                    <a class="btn {{ $penjualanHariIni ? '' : 'btn-merah' }}"
                       href="{{ $penjualanHariIni ? route('cabang.penjualan.lihat', $penjualanHariIni) : route('cabang.penjualan.catat') }}"
                       @unless ($penjualanHariIni) data-popup="panjang" @endunless>
                        {{ $penjualanHariIni ? 'Lihat' : 'Catat Sekarang' }}
                    </a>
                </div>
                @endunless
            </div>
        </section>

        <nav class="tombol-cepat-grid" aria-label="Catat cepat">
            @boleh('terima-barang.lihat')
                <x-tombol-cepat :tautan="route('cabang.terima-barang')" ikon="masuk" label="Terima Barang"
                                :ket="$jumlah['kiriman_belum_dichecklist'] > 0 ? 'Perlu dihitung' : null"
                                :angka="$jumlah['kiriman_belum_dichecklist']" />
            @endboleh
            @if ($bolehMinta)
                <x-tombol-cepat :tautan="route('cabang.permintaan.buat', $jumlahUsulan > 0 ? ['usulan' => 1] : [])" ikon="panah-kiri" label="Minta Barang" data-popup="panjang"
                                :ket="$jumlahUsulan > 0 ? $jumlahUsulan.' perlu diminta' : ($permintaanMenunggu > 0 ? $permintaanMenunggu.' menunggu' : null)"
                                :angka="$jumlahUsulan" />
            @else
                @boleh('permintaan.lihat')
                    <x-tombol-cepat :tautan="route('cabang.permintaan')" ikon="panah-kiri" label="Permintaan"
                                    :ket="$permintaanMenunggu > 0 ? $permintaanMenunggu.' menunggu' : null" />
                @endboleh
            @endif
            @boleh('barang-keluar.catat')
                <x-tombol-cepat :tautan="route('cabang.barang-keluar.catat')" ikon="keluar" label="Barang Rusak" data-popup="panjang" />
            @endboleh
            @boleh('opname.lihat')
                <x-tombol-cepat :tautan="route('cabang.opname')" ikon="ceklis" label="Opname"
                                :ket="$opnameBerjalan ? 'Sedang berjalan' : null" />
            @endboleh
        </nav>

        <div class="dasbor-grafik">
            <x-grafik.pergerakan :lokasi="$lokasi" />
            <x-grafik.kondisi :jumlah="$jumlahPerLampu" rute="cabang.stok" :sub="$lokasi->nama" />
        </div>

        <x-grafik.penjualan :lokasi="$lokasi" />

        <div class="dasbor-dua">
            <section class="panel">
                <div class="panel-head">
                    <h2><x-ikon-judul nama="kotak" />Segera Habis</h2>
                </div>
                @include('dashboard._bahan', ['daftar' => $segeraHabis, 'ruteStok' => route('cabang.stok'), 'ruteRiwayat' => 'cabang.stok.riwayat'])
            </section>
        </div>
    @endif
@endsection
