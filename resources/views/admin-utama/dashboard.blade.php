{{--
    Dashboard Admin Utama (HP dan laptop). Variabel dari AdminUtama\DashboardController:
      $peringatan (null bila modul peringatan dini mati), $ringkasan, $jumlahLampu, $kirimanJalan, $pilihanTingkat, $maksPeringatan,
      $antrean (baris AntreanPersetujuanService), $jumlahWajar, $jumlahMendesak, $maksAntrean, $pilihanJenis.
    $jumlah dan $pengguna dari LayoutComposer.
    Urutan: banner sapaan, kartu angka, grafik pergerakan (kiri) + donat kondisi stok semua lokasi (kanan), antrean persetujuan,
    lalu kondisi per lokasi (kiri) dan peringatan dini (kanan). HP & tablet: satu kolom dengan urutan yang sama.
--}}
@extends('layouts.app')

@php
    $lampu = \App\Services\PantauanStokService::class;
    $merah = $jumlahLampu[$lampu::LAMPU_MERAH];
    $kuning = $jumlahLampu[$lampu::LAMPU_KUNING];
    $lokasiBermasalah = $ringkasan->filter(fn ($r) => $r['lampu'][$lampu::LAMPU_MERAH] + $r['lampu'][$lampu::LAMPU_KUNING] > 0)->count();
    $peringatanTinggi = $peringatan?->where('tingkat', \App\Services\PeringatanDiniService::TINGKAT_TINGGI)->count() ?? 0;
@endphp

@section('judul', 'Dashboard')
@section('segar', 'ya')
@section('sub', now()->translatedFormat('l, j F Y'))

@section('aksi')
    <a class="btn" href="{{ route('admin-utama.impor-sekaligus') }}" data-popup>
        <x-ikon nama="keluar" />
        <span>Impor Sekaligus</span>
    </a>
@endsection

@section('content')
    @include('dashboard._sapaan', [
        'ruteCari' => \App\View\MenuSidebar::ruteCari($pengguna),
        'ringkas'  => $ringkasan->count().' lokasi aktif · '.$merah.' bahan habis, '.$kuning.' menipis · '.$antrean->count().' dokumen menunggu keputusan.',
    ])

    <div class="kartu-grid">
        <x-kartu-angka warna="ungu" ikon="ceklis" label="Menunggu Keputusan" :angka="$antrean->count()" :tautan="route('admin-utama.persetujuan')"
                       :nada="$jumlahMendesak > 0 ? 'bahaya' : ($antrean->isNotEmpty() ? 'perhatian' : 'baik')"
                       :ket="$antrean->isEmpty() ? 'Semua beres' : ($jumlahMendesak > 0 ? $jumlahMendesak.' mendesak' : $jumlahWajar.' wajar')" />
        @if ($peringatan !== null)
            <x-kartu-angka warna="jingga" ikon="peringatan" label="Peringatan" :angka="$peringatan->count()"
                           :nada="$peringatanTinggi > 0 ? 'bahaya' : ($peringatan->isNotEmpty() ? 'perhatian' : 'baik')"
                           :ket="$peringatanTinggi > 0 ? $peringatanTinggi.' perlu segera' : null"
                           :tautan="$peringatan->isNotEmpty() ? '#peringatan-dini' : null" />
        @endif
        <x-kartu-angka ikon="kotak" label="Stok Habis / Menipis" :angka="$merah.' / '.$kuning" :tautan="route('admin-utama.stok')"
                       :nada="$merah > 0 ? 'bahaya' : ($kuning > 0 ? 'perhatian' : 'baik')"
                       :ket="$lokasiBermasalah > 0 ? 'di '.$lokasiBermasalah.' lokasi' : 'Semua aman'" />
        <x-kartu-angka warna="biru" ikon="truk" label="Kiriman di Jalan" :angka="$kirimanJalan" :tautan="route('admin-utama.kiriman')" />
    </div>

    <div class="dasbor-grafik">
        <x-grafik.pergerakan />
        <x-grafik.kondisi :jumlah="$jumlahLampu" jangkar="#ringkasan-lokasi" judul="Kondisi Stok" sub="Gabungan semua lokasi" />
    </div>

    <x-grafik.penjualan :pilihan-lokasi="\App\Models\Cabang::query()->cabang()->orderBy('nama')->pluck('nama', 'id')" />

    <section class="panel" id="antrean-persetujuan">
        <div class="panel-head">
            <h2><x-ikon-judul nama="ceklis" />Antrean Persetujuan <span class="num">{{ $antrean->count() }}</span></h2>
        </div>

        @if ($antrean->isEmpty())
            <x-kosong gambar="selesai" kecil>Semua sudah diputuskan.</x-kosong>
        @else
            @include('admin-utama.persetujuan._tabel-antrean', ['daftar' => $antrean->take($maksAntrean), 'pilih' => $jumlahWajar > 0 ? 'pilih-setujui' : null])
            @if ($jumlahWajar > 0)
            <x-pilih.bilah id="pilih-setujui" :action="route('admin-utama.persetujuan.wajar.setujui')" tombol="Setujui yang Dicentang" hijau
                           konfirmasi="Semua dokumen wajar yang dicentang disetujui sekaligus dan tidak bisa diubah lagi."
                           judul-konfirmasi="Setujui yang Dicentang?" tombol-konfirmasi="Ya, Setujui" />
            @endif
            <div class="panel-kaki">
                <a class="link" href="{{ route('admin-utama.persetujuan') }}">
                    {{ $antrean->count() > $maksAntrean ? 'Lihat semua ('.$antrean->count().')' : 'Buka Persetujuan' }}
                </a>
            </div>
        @endif
    </section>

    <div class="dasbor-dua">
        <section class="panel" id="ringkasan-lokasi">
            <div class="panel-head">
                <h2><x-ikon-judul nama="lokasi" />Ringkasan per Lokasi</h2>
                <div class="kanan"><a class="link" href="{{ route('admin-utama.stok') }}">Buka stok</a></div>
            </div>
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-dokumen tabel-lokasi">
                    <thead>
                        <tr>
                            <th>Lokasi</th>
                            <th>Kondisi Stok</th>
                            <th>Penjualan Hari Ini</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ringkasan as $r)
                            @php
                                $perhatian = \Illuminate\Support\Arr::except($r['lampu'], $lampu::LAMPU_HIJAU);
                                $total = max(1, array_sum($r['lampu']));
                            @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Lokasi">
                                    <a class="tautan-baris nama-barang" href="{{ route('admin-utama.stok', ['lokasi' => $r['lokasi']->id]) }}">{{ $r['lokasi']->nama }}</a>
                                    <span class="barang-ket">{{ $r['lokasi']->isPusat() ? 'Gudang Pusat' : 'Cabang' }}@if ($r['lokasi']->opname_aktif) · sedang opname @endif</span>
                                </td>
                                <td class="kartu-lebar" data-label="">
                                    <span class="batang-lampu" aria-hidden="true">
                                        @foreach ($r['lampu'] as $l => $n)
                                            <span class="{{ $lampu::TAMPILAN_LAMPU[$l][1] }}" style="width: {{ round($n / $total * 100, 1) }}%"></span>
                                        @endforeach
                                    </span>
                                    <x-ringkas-lampu class="satuan" :jumlah="array_sum($perhatian) > 0 ? $perhatian : $r['lampu']" />
                                </td>
                                <td class="kartu-utama" data-label="">
                                    @if ($r['lokasi']->isPusat())
                                        <span class="satuan">—</span>
                                    @elseif ($r['porsiHariIni'] !== null)
                                        <span class="badge b-setuju">{{ \App\Support\Angka::tampil($r['porsiHariIni']) }} porsi</span>
                                    @else
                                        <span class="badge b-tolak">Belum Dicatat</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="kosong">
                                    <x-kosong gambar="kotak" kecil>
                                        Belum ada lokasi. <a class="link" href="{{ route('admin-utama.lokasi.tambah') }}" data-popup>Tambah lokasi</a>
                                    </x-kosong>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($peringatan !== null)
            <section class="panel" id="peringatan-dini">
                <div class="panel-head">
                    <h2><x-ikon-judul nama="peringatan" />Peringatan <span class="num">{{ $peringatan->count() }}</span></h2>
                </div>

                @forelse ($peringatan->take($maksPeringatan) as $p)
                    @include('dashboard._peringatan')
                @empty
                    <x-kosong gambar="selesai" kecil>Aman, tidak ada yang perlu dicek.</x-kosong>
                @endforelse

                @if ($peringatan->count() > $maksPeringatan)
                    <details class="peringatan-lain">
                        <summary>{{ $peringatan->count() - $maksPeringatan }} peringatan lain</summary>
                        @foreach ($peringatan->slice($maksPeringatan) as $p)
                            @include('dashboard._peringatan')
                        @endforeach
                    </details>
                @endif
            </section>
        @endif
    </div>
@endsection
