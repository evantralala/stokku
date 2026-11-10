{{--
    Laporan per kategori (gaya Majoo). Variabel dari LaporanController@kategori:
      $perMenu (penjualan per kategori menu: kategori, menu, porsi, persen, terlaris),
      $perBahan (bahan dapur per kategori bahan: kategori, dipakai, rusak, hilang, lewat_toleransi), saringan.
    Jumlah bahan antarkategori tidak dijumlahkan karena satuannya berbeda; yang dihitung adalah banyaknya bahan.
--}}
@extends('layouts.app')

@include('admin-utama.laporan._kepala')

@section('content')
    @include('admin-utama.laporan._saring')

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Penjualan per Kategori</h2>
                <div class="sub">Total {{ \App\Support\Angka::tampil($perMenu->sum('porsi')) }} porsi</div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas tabel-kategori">
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th class="ka">Porsi</th>
                        <th>Bagian</th>
                        <th class="ka">Menu Terjual</th>
                        <th>Terlaris</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($perMenu as $r)
                        <tr>
                            <td class="kartu-judul" data-label="Kategori"><span class="nama-barang">{{ $r['kategori'] }}</span></td>
                            <td class="ka num kartu-utama" data-label="Porsi">{{ \App\Support\Angka::tampil($r['porsi']) }}</td>
                            <td data-label="Bagian">
                                <span class="batang-persen" style="--persen: {{ min(100, $r['persen']) }}%" aria-hidden="true"></span>
                                <span class="num">{{ \App\Support\Angka::tampil($r['persen'], 1) }}%</span>
                            </td>
                            <td class="ka num" data-label="Menu terjual">{{ $r['menu'] }}</td>
                            <td data-label="Terlaris">
                                @if ($r['terlaris'])
                                    {{ $r['terlaris']->nama }} <span class="satuan">{{ \App\Support\Angka::tampil($r['terlaris']->porsi) }} porsi</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="kosong"><x-kosong gambar="grafik">Tidak ada penjualan pada periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Bahan per Kategori</h2>
                <div class="sub">Banyaknya bahan per kategori. Rincian jumlah ada di laporan Penjualan & pemakaian.</div>
            </div>
        </div>
        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th class="ka">Terpakai</th>
                        <th class="ka">Ada yang Rusak</th>
                        <th class="ka">Hilang Saat Opname</th>
                        <th class="ka">Lewat Toleransi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($perBahan as $r)
                        <tr>
                            <td class="kartu-judul" data-label="Kategori"><span class="nama-barang">{{ $r['kategori'] }}</span></td>
                            <td class="ka num kartu-utama" data-label="Terpakai">{{ $r['dipakai'] }} bahan</td>
                            <td @class(['ka num', 'hp-sembunyi' => ! $r['rusak']]) data-label="Ada yang rusak">{{ $r['rusak'] ?: '—' }}</td>
                            <td @class(['ka num', 'hp-sembunyi' => ! $r['hilang']]) data-label="Hilang saat opname">{{ $r['hilang'] ?: '—' }}</td>
                            <td @class(['ka num', 'teks-kurang' => $r['lewat_toleransi'] > 0, 'hp-sembunyi' => ! $r['lewat_toleransi']]) data-label="Lewat toleransi">{{ $r['lewat_toleransi'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="kosong"><x-kosong gambar="grafik">Belum ada pemakaian, barang rusak, atau koreksi opname pada periode ini.</x-kosong></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
