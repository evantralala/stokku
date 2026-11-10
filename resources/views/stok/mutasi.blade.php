{{--
    Riwayat stok satu lokasi. Variabel dari PantauStok::mutasi():
      $lokasi, $rute, $tampil ('hari' | 'transaksi' | 'harian'), $periode, $tanggal (tab per hari), $semua (tampilkan semua bahan),
      $filter (arah, jenis, kategori, cari), $perHari (baris RiwayatStokService::perHari),
      $mutasi (paginator StokMutasi, hanya tab transaksi), $dokumen ([mutasi id => ['nomor', 'url']]),
      $pemakaian ([tanggal => ['tanggal', 'baris' => [bahan, pakai, rusak]]]), $pilihanArah, $pilihanJenis,
      $pilihanKategori, $parameterLokasi.
    Tab "Per hari": stok awal, masuk, keluar, dan stok akhir tiap bahan pada satu tanggal (bawaan hari ini), dengan tombol
    hari sebelumnya/berikutnya. Tab "Masuk & keluar": setiap mutasi sah menurut waktu dicatat. Tab "Pemakaian harian":
    pemakaian dapur dan barang rusak per tanggal dokumennya.
--}}
@extends('layouts.app')

@section('segar', 'ya')

@php
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    $hari = $tampil === 'hari';
    $harian = $tampil === 'harian';
    $saring = array_filter(['kategori' => $filter['kategori'], 'cari' => $filter['cari']], fn ($v) => $v !== null && $v !== '');
    $dasar = $parameterLokasi + ['dari' => $periode->dariTanggal(), 'sampai' => $periode->sampaiTanggal()] + $saring;
    $dasarHari = $parameterLokasi + ['tampil' => 'hari', 'tanggal' => $tanggal->toDateString()] + $saring + ($semua ? ['semua' => 1] : []);
    $labelHari = fn ($t) => $t->isToday() ? 'Hari ini' : ($t->isYesterday() ? 'Kemarin' : $t->translatedFormat('l'));
    $keTanggal = fn ($t) => route($rute.'.mutasi', ['tanggal' => $t->toDateString()] + $dasarHari);
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute, $parameterLokasi) }}">Stok {{ $lokasi->nama }}</a>
@endsection
@section('judul', 'Riwayat Stok')
@section('sub', $lokasi->nama.' · '.($hari ? $labelHari($tanggal).', '.$tanggal->translatedFormat('j M Y') : $periode->label()))

@section('aksi')
    <x-tombol-unduh :href="$hari
        ? route($rute.'.mutasi.unduh', $dasarHari)
        : route($rute.'.mutasi.unduh', $dasar + array_filter([
            'tampil' => $tampil,
            'arah'   => $harian ? null : ($filter['arah'] ?: null),
            'jenis'  => $harian ? null : ($filter['jenis'] ?: null),
        ]))" />
@endsection

@section('content')
    <nav class="tab-riwayat" aria-label="Jenis riwayat">
        <a class="chip" href="{{ route($rute.'.mutasi', $parameterLokasi + ['tampil' => 'hari'] + $saring) }}" @if ($hari) aria-current="true" @endif>Per Hari</a>
        <a class="chip" href="{{ route($rute.'.mutasi', $dasar + ['tampil' => 'transaksi']) }}" @if ($tampil === 'transaksi') aria-current="true" @endif>Masuk & Keluar</a>
        <a class="chip" href="{{ route($rute.'.mutasi', $dasar + ['tampil' => 'harian']) }}" @if ($harian) aria-current="true" @endif>Pemakaian Harian</a>
    </nav>

    @if ($hari)
        @php
            $kemarin = $tanggal->copy()->subDay();
            $besok = $tanggal->copy()->addDay();
            $berubah = $perHari->filter(fn ($r) => $r['masuk'] > 0 || $r['keluar'] > 0)->count();
        @endphp

        <section class="panel pilih-hari">
            <div class="pilih-hari-geser">
                <a class="btn tombol-hari" href="{{ $keTanggal($kemarin) }}" aria-label="Hari sebelumnya, {{ $kemarin->translatedFormat('j M Y') }}">
                    <x-ikon nama="panah-kanan" class="ikon-balik" />
                </a>
                <div class="pilih-hari-label">
                    <strong>{{ $labelHari($tanggal) }}</strong>
                    <span class="satuan">{{ $tanggal->translatedFormat('j F Y') }}</span>
                </div>
                @if ($tanggal->isToday())
                    <span class="btn tombol-hari" aria-disabled="true"><x-ikon nama="panah-kanan" /></span>
                @else
                    <a class="btn tombol-hari" href="{{ $keTanggal($besok) }}" aria-label="Hari berikutnya, {{ $besok->translatedFormat('j M Y') }}">
                        <x-ikon nama="panah-kanan" />
                    </a>
                @endif
            </div>

            <form class="pilih-hari-form" method="GET" action="{{ route($rute.'.mutasi') }}">
                @foreach ($parameterLokasi + ['tampil' => 'hari'] + ($semua ? ['semua' => 1] : []) as $kunci => $nilai)
                    <input type="hidden" name="{{ $kunci }}" value="{{ $nilai }}">
                @endforeach
                <nav class="chip-saring chip-geser" aria-label="Tanggal cepat">
                    <a class="chip" href="{{ $keTanggal(today()) }}" @if ($tanggal->isToday()) aria-current="true" @endif>Hari Ini</a>
                    <a class="chip" href="{{ $keTanggal(today()->subDay()) }}" @if ($tanggal->isYesterday()) aria-current="true" @endif>Kemarin</a>
                </nav>
                <div class="form-grid saring-grid">
                    <x-form.teks name="tanggal" label="Tanggal" type="date" :value="$tanggal->toDateString()" :max="now()->toDateString()" data-kirim-otomatis />
                    <x-form.pilihan name="kategori" label="Kategori" :pilihan="$pilihanKategori" :value="$filter['kategori']" kosong="Semua Kategori" />
                    <x-form.teks name="cari" label="Cari Bahan" type="search" :value="$filter['cari']" autocomplete="off" placeholder="Nama bahan" lebar />
                </div>
                <div class="simpan-bar">
                    <div class="simpan-aksi">
                        <button type="submit" class="btn btn-merah">Tampilkan</button>
                    </div>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>{{ $berubah }} bahan berubah</h2>
                <nav class="chip-saring" aria-label="Bahan yang ditampilkan">
                    <a class="chip" href="{{ route($rute.'.mutasi', \Illuminate\Support\Arr::except($dasarHari, 'semua')) }}" @unless ($semua) aria-current="true" @endunless>Yang Berubah</a>
                    <a class="chip" href="{{ route($rute.'.mutasi', $dasarHari + ['semua' => 1]) }}" @if ($semua) aria-current="true" @endif>Semua Bahan</a>
                </nav>
            </div>

            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-per-hari">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="ka">Stok Awal</th>
                            <th class="ka">Masuk</th>
                            <th class="ka">Keluar</th>
                            <th class="ka">Stok Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($perHari->groupBy(fn ($r) => $r['bahan']->kategori_id) as $baris)
                            <tr class="baris-kategori"><th colspan="5">{{ $baris->first()['bahan']->kategori->nama }}</th></tr>
                            @foreach ($baris as $r)
                                @php $b = $r['bahan']; @endphp
                                <tr @class(['baris-diam' => $r['masuk'] == 0 && $r['keluar'] == 0])>
                                    <td class="kartu-judul" data-label="Bahan">
                                        <a class="nama-barang" href="{{ route($rute.'.riwayat', [$b] + $parameterLokasi) }}">{{ $b->nama }}</a>
                                        @if ($r['rincian'])
                                            <div class="satuan rincian-hari">
                                                @foreach ($r['rincian'] as $label => $jumlah)
                                                    <span>{{ $label }} {{ $jumlah > 0 ? '+' : '' }}{{ \App\Support\Angka::tampil($b->keSatuanBeli($jumlah)) }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="ka num {{ $r['awal'] < 0 ? 'teks-kurang' : '' }}" data-label="Stok awal">{{ $keBeli($b, $r['awal']) }}</td>
                                    <td class="ka num teks-masuk" data-label="Masuk">{{ $r['masuk'] > 0 ? '+'.$keBeli($b, $r['masuk']) : '—' }}</td>
                                    <td class="ka num teks-kurang" data-label="Keluar">{{ $r['keluar'] > 0 ? '-'.$keBeli($b, $r['keluar']) : '—' }}</td>
                                    <td class="ka num stok-akhir {{ $r['akhir'] < 0 ? 'teks-kurang' : '' }}" data-label="Stok akhir">{{ $keBeli($b, $r['akhir']) }}</td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="5" class="kosong">
                                    <x-kosong gambar="grafik">
                                        Tidak ada perubahan stok pada {{ $tanggal->translatedFormat('j M Y') }}.
                                        @unless ($semua)
                                            <a class="link" href="{{ route($rute.'.mutasi', $dasarHari + ['semua' => 1]) }}">Lihat stok semua bahan</a>
                                        @endunless
                                    </x-kosong>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @else
    <form class="panel saring-laporan" method="GET" action="{{ route($rute.'.mutasi') }}">
        @foreach ($parameterLokasi + ['tampil' => $tampil] as $kunci => $nilai)
            <input type="hidden" name="{{ $kunci }}" value="{{ $nilai }}">
        @endforeach

        <nav class="chip-saring chip-geser saring-periode" aria-label="Periode cepat">
            @foreach (\App\Support\Periode::pilihanCepat() as $label => [$dari, $sampai])
                <a class="chip" href="{{ route($rute.'.mutasi', ['dari' => $dari, 'sampai' => $sampai, 'tampil' => $tampil] + $dasar) }}"
                   @if ($periode->dariTanggal() === $dari && $periode->sampaiTanggal() === $sampai) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="form-grid saring-grid">
            <x-form.teks name="dari" label="Dari" type="date" :value="$periode->dariTanggal()" :max="now()->toDateString()" />
            <x-form.teks name="sampai" label="Sampai" type="date" :value="$periode->sampaiTanggal()" :max="now()->toDateString()" />
            @unless ($harian)
                <x-form.pilihan name="arah" label="Masuk / Keluar" :pilihan="$pilihanArah" :value="$filter['arah']" kosong="Semua" />
                <x-form.pilihan name="jenis" label="Jenis" :pilihan="$pilihanJenis" :value="$filter['jenis']" kosong="Semua Jenis" />
            @endunless
            <x-form.pilihan name="kategori" label="Kategori" :pilihan="$pilihanKategori" :value="$filter['kategori']" kosong="Semua Kategori" />
            <x-form.teks name="cari" label="Cari Bahan" type="search" :value="$filter['cari']" autocomplete="off" placeholder="Nama bahan" />
        </div>

        <div class="simpan-bar">
            <div class="simpan-aksi">
                <button type="submit" class="btn btn-merah">Tampilkan</button>
            </div>
        </div>
    </form>

    @if ($harian)
        @forelse ($pemakaian as $hariPakai)
            <section class="panel">
                <div class="panel-head">
                    <h2>{{ $hariPakai['tanggal']->translatedFormat('l, j M Y') }}</h2>
                    <span class="satuan">{{ $hariPakai['baris']->count() }} bahan</span>
                </div>
                <div class="tabel-bungkus">
                    <table class="tabel-kartu tabel-ringkas">
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th class="ka">Dipakai</th>
                                <th class="ka">Rusak</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hariPakai['baris'] as $r)
                                <tr>
                                    <td class="kartu-judul" data-label="Bahan">
                                        <a class="nama-barang" href="{{ route($rute.'.riwayat', [$r['bahan']] + $parameterLokasi) }}">{{ $r['bahan']->nama }}</a>
                                    </td>
                                    <td class="ka num" data-label="Dipakai">{{ $r['pakai'] > 0 ? $keBeli($r['bahan'], $r['pakai']) : '—' }}</td>
                                    <td class="ka num {{ $r['rusak'] > 0 ? 'teks-kurang' : '' }}" data-label="Rusak">{{ $r['rusak'] > 0 ? $keBeli($r['bahan'], $r['rusak']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <section class="panel">
                <x-kosong gambar="grafik">Belum ada pemakaian atau barang rusak di periode ini.</x-kosong>
            </section>
        @endforelse
    @else
        <section class="panel">
            <div class="tabel-bungkus">
                <table class="tabel-kartu">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th>Kejadian</th>
                            <th>Dokumen</th>
                            <th class="ka">Masuk / Keluar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($mutasi as $m)
                            @php $doc = $dokumen[$m->id] ?? ['nomor' => '—', 'url' => null]; @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Bahan">
                                    <a class="nama-barang" href="{{ route($rute.'.riwayat', [$m->bahanBaku] + $parameterLokasi) }}">{{ $m->bahanBaku->nama }}</a>
                                    <div class="satuan">{{ $m->dibuat_pada->translatedFormat('j M Y, H.i') }} · {{ $m->pembuat?->nama ?? 'Sistem' }}</div>
                                </td>
                                <td data-label="Kejadian">{{ $m->labelJenis() }}</td>
                                <td class="num" data-label="Dokumen">
                                    @if ($doc['url'])
                                        <a class="link" href="{{ $doc['url'] }}">{{ $doc['nomor'] }}</a>
                                    @else
                                        {{ $doc['nomor'] }}
                                    @endif
                                </td>
                                <td class="ka num {{ (float) $m->jumlah < 0 ? 'teks-kurang' : 'teks-masuk' }}" data-label="Masuk / keluar">
                                    {{ (float) $m->jumlah > 0 ? '+' : '' }}{{ $keBeli($m->bahanBaku, $m->jumlah) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="kosong"><x-kosong gambar="grafik">Belum ada barang masuk atau keluar di periode ini.</x-kosong></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-master.halaman :daftar="$mutasi" />
        </section>
    @endif
    @endif
@endsection
