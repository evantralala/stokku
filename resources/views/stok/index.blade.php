{{--
    Pantauan stok satu lokasi. Variabel dari PantauStok::index():
      $lokasi, $rute, $kelompok (per kategori: kategori, baris, lampu),
      $bukaSemua, $jumlahPerLampu, $filter, $parameterLokasi,
      $pilihanLampu, $pilihanStatus, $pilihanKategori, $pilihanLokasi (null untuk admin),
      $modeIsi (minta: Admin Cabang membuat permintaan; tambah: stok langsung bertambah),
      $pilihanIsi ([bahan id => sudah dalam permintaan berjalan] untuk bahan aktif yang habis/menipis; kosong bila tidak boleh isi),
      $aksiStok (tombol tambah/ubah/excel/isi yang boleh tampil; Admin Cabang tidak punya tambah/ubah/excel).
      Kosongkan Stok hanya untuk Admin Utama.
    Kotak centang di kanan baris memilih bahan (data-isi = bisa diisi, data-kosong = bisa dikosongkan, data-perlu = habis/menipis).
    Admin Cabang hanya bisa mencentang yang habis/menipis; Admin Utama/Pusat semua bahan aktif, dan Admin Utama juga yang stoknya tidak 0.
    Bilah bawah: Isi Terpilih / Kosongkan Terpilih untuk yang dicentang; tanpa centang tombol Isi membuka semua yang habis/menipis
    dan terlihat. Pilihan dikelola resources/js/pilih-isi-stok.js; tanpa JavaScript tombol Isi membuka semua yang habis/menipis.
    Susunan: kartu angka lampu (tautan saringan), kartu alat (lokasi, cari, chip lampu), lalu rel kategori + daftar.
    Saringan dikirim lewat query string (tanpa JavaScript tetap jalan). Dengan JavaScript, cari, chip lampu, dan rel kategori
    menyaring langsung di halaman (resources/js/saring-stok.js) selama barisnya sudah ada di halaman.
    Filter status hanya datang dari tautan, mis. notifikasi stok minus.
    Kategori yang punya lampu merah/kuning terbuka; semuanya terbuka bila ada saringan atau hanya satu kategori.
    Nama bahan membuka intip bahan (popup). Tombol Ubah membuka form ubah stok (popup) untuk bahan aktif;
    ikon sampah (Admin Utama, bahan yang stoknya tidak 0) membuka popup kosongkan untuk bahan itu saja.
    Satu kotak centang per baris untuk semua aksi banyak bahan: centang dulu, lalu pilih Isi atau Kosongkan di bilah bawah.
    Di laptop lebar kolom mengikuti lebar daftar (container query di stok.css): bila daftar sempit, kolom Minimum
    pindah ke bawah nama bahan (.stok-min-sempit) supaya nama bahan dan angka stok tidak bertumpuk.
    Kolom Ubah/Pilih tidak memakan tempat bila perannya tidak punya aksi itu (.tanpa-ubah/.tanpa-pilih).
--}}
@extends('layouts.app')

@php
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    $parameter = fn (array $ubah = []) => array_filter($ubah + [
        'lokasi'   => $pilihanLokasi ? $lokasi->id : null,
        'lampu'    => $filter['lampu'],
        'status'   => $filter['status'],
        'kategori' => $filter['kategori'],
        'cari'     => $filter['cari'],
    ], fn ($v) => $v !== null && $v !== '');
    $jumlahBaris = $kelompok->sum(fn ($k) => count($k['baris']));
    $labelIsi = $modeIsi === \App\Services\IsiStokService::MODE_MINTA ? 'Minta' : 'Isi';
    $bisaDipilih = collect($pilihanIsi)->reject(fn ($diminta) => $diminta);
    $bisaKosongkan = $rute === 'admin-utama.stok';
    $adaCentang = fn (array $r) => $bisaDipilih->has($r['bahan']->id) || ($bisaKosongkan && $r['saldo'] != 0);
    $semuaBaris = $kelompok->flatMap(fn ($k) => $k['baris']);
    $jumlahPerlu = $semuaBaris->filter(fn ($r) => $bisaDipilih->has($r['bahan']->id) && $r['lampu'] !== \App\Services\PantauanStokService::LAMPU_HIJAU)->count();
    $adaPilihan = $semuaBaris->contains($adaCentang);
    $teksKosong = match (true) {
        $bisaKosongkan => 'Centang bahan untuk diisi atau dikosongkan',
        $modeIsi === \App\Services\IsiStokService::MODE_TAMBAH => 'Centang bahan yang mau diisi',
        default => 'Centang bahan habis atau menipis',
    };
@endphp

@section('judul', 'Stok')
@section('sub', $lokasi->nama.' · '.array_sum($jumlahPerLampu).' bahan')

@section('aksi')
    <a class="btn tombol-ikon-hp" href="{{ route($rute.'.mutasi', $parameterLokasi) }}" aria-label="Riwayat masuk dan keluar">
        <x-ikon nama="riwayat" />
        <span>Riwayat</span>
    </a>
    <a class="btn tombol-ikon-hp" href="{{ route($rute.'.lembar-opname', $parameterLokasi) }}" data-popup aria-label="Lembar stock opname PDF">
        <x-ikon nama="ceklis" />
        <span>Lembar Opname</span>
    </a>
    <x-tombol-unduh :href="route($rute.'.unduh', $parameterLokasi)" />
    @if ($aksiStok['excel'])
        <a class="btn tombol-ikon-hp" href="{{ route($rute.'.excel', $parameterLokasi) }}" data-popup aria-label="Perbarui stok lewat Excel">
            <x-ikon nama="keluar" />
            <span>Perbarui Lewat Excel</span>
        </a>
    @endif
@endsection

@section('content')
    @php $lampuStok = \App\Services\PantauanStokService::class; @endphp
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka warna="ungu" ikon="kotak" label="Total Bahan" :angka="array_sum($jumlahPerLampu)" :ket="'dipantau di '.$lokasi->nama"
                       :tautan="route($rute, $parameter(['lampu' => '', 'status' => '']))" />
        <x-kartu-angka warna="merah" ikon="peringatan" label="Habis" :angka="$jumlahPerLampu[$lampuStok::LAMPU_MERAH]"
                       :nada="$jumlahPerLampu[$lampuStok::LAMPU_MERAH] > 0 ? 'bahaya' : 'biasa'" ket="stok 0 atau minus"
                       :tautan="route($rute, $parameter(['lampu' => $lampuStok::LAMPU_MERAH, 'status' => '']))" />
        <x-kartu-angka warna="jingga" ikon="grafik" label="Menipis" :angka="$jumlahPerLampu[$lampuStok::LAMPU_KUNING]"
                       :nada="$jumlahPerLampu[$lampuStok::LAMPU_KUNING] > 0 ? 'perhatian' : 'biasa'" ket="di bawah / dekat minimum"
                       :tautan="route($rute, $parameter(['lampu' => $lampuStok::LAMPU_KUNING, 'status' => '']))" />
        <x-kartu-angka warna="hijau" ikon="ceklis" label="Aman" :angka="$jumlahPerLampu[$lampuStok::LAMPU_HIJAU]" nada="baik" ket="di atas minimum"
                       :tautan="route($rute, $parameter(['lampu' => $lampuStok::LAMPU_HIJAU, 'status' => '']))" />
    </div>

    <div class="panel alat-stok" data-saring-stok="daftar-stok"
         data-server-lampu="{{ $filter['lampu'] }}" data-server-status="{{ $filter['status'] }}"
         data-server-kategori="{{ $filter['kategori'] }}" data-server-cari="{{ $filter['cari'] }}">
        <form class="alat-stok-form" method="GET" action="{{ route($rute) }}">
            @if ($pilihanLokasi)
                <label class="tersembunyi" for="pilih-lokasi">Lokasi</label>
                <select class="isian pilih-lokasi" id="pilih-lokasi" name="lokasi" data-kirim-otomatis>
                    @foreach ($pilihanLokasi as $id => $nama)
                        <option value="{{ $id }}" @selected($lokasi->id === $id)>{{ $nama }}</option>
                    @endforeach
                </select>
            @endif
            <div class="kotak-cari">
                <x-ikon nama="cari" width="18" height="18" />
                <label class="tersembunyi" for="cari-stok">Cari Bahan</label>
                <input class="kotak-cari-isian" id="cari-stok" type="search" name="cari" value="{{ $filter['cari'] }}"
                       placeholder="Cari bahan" autocomplete="off" enterkeyhint="search" data-cari-utama data-cari-instan>
            </div>
            @foreach (['lampu', 'status', 'kategori'] as $kunci)
                @if ($filter[$kunci] !== '' && $filter[$kunci] !== null)
                    <input type="hidden" name="{{ $kunci }}" value="{{ $filter[$kunci] }}">
                @endif
            @endforeach
            <noscript><button type="submit" class="btn btn-sm">Tampilkan</button></noscript>
        </form>

        <nav class="chip-saring" aria-label="Saring menurut lampu stok">
            <a class="chip" href="{{ route($rute, $parameter(['lampu' => '', 'status' => ''])) }}" data-saring-lampu=""
               @if ($filter['lampu'] === '' && $filter['status'] === '') aria-current="true" @endif>
                Semua <span class="num">{{ array_sum($jumlahPerLampu) }}</span>
            </a>
            @foreach ($pilihanLampu as $nilai => [$teks, $kelas])
                <a class="chip {{ $kelas }}" href="{{ route($rute, $parameter(['lampu' => $nilai, 'status' => ''])) }}" data-saring-lampu="{{ $nilai }}"
                   @if ($filter['lampu'] === $nilai) aria-current="true" @endif>
                    <span class="lampu-titik" aria-hidden="true"></span>{{ $teks }} <span class="num">{{ $jumlahPerLampu[$nilai] }}</span>
                </a>
            @endforeach
            @if ($filter['status'] !== '')
                <a class="chip" href="{{ route($rute, $parameter(['status' => ''])) }}" aria-current="true" aria-label="Hapus saringan {{ $pilihanStatus[$filter['status']][0] }}">
                    {{ $pilihanStatus[$filter['status']][0] }} <span class="chip-hapus" aria-hidden="true">×</span>
                </a>
            @endif
        </nav>
    </div>

    <div class="tata-stok">
        <nav class="rel-kategori" aria-label="Kategori">
            <a class="rel-kategori-item" href="{{ route($rute, $parameter(['kategori' => ''])) }}" data-saring-kategori=""
               @if (! $filter['kategori']) aria-current="true" @endif>
                <span class="rel-kategori-nama">Semua Kategori</span>
                <span class="num">{{ $jumlahBaris }}</span>
            </a>
            @foreach ($kelompok as $k)
                <a class="rel-kategori-item" href="{{ route($rute, $parameter(['kategori' => $k['kategori']->id])) }}" data-saring-kategori="{{ $k['kategori']->id }}"
                   @if ($filter['kategori'] === $k['kategori']->id) aria-current="true" @endif>
                    <span class="rel-kategori-nama">{{ $k['kategori']->nama }}</span>
                    <span class="rel-kategori-lampu">
                        @foreach ([\App\Services\PantauanStokService::LAMPU_MERAH, \App\Services\PantauanStokService::LAMPU_KUNING] as $lampu)
                            @if ($k['lampu'][$lampu] > 0)
                                <span class="{{ $pilihanLampu[$lampu][1] }}"><span class="lampu-titik" aria-hidden="true"></span>{{ $k['lampu'][$lampu] }}<span class="tersembunyi"> {{ mb_strtolower($pilihanLampu[$lampu][0]) }}</span></span>
                            @endif
                        @endforeach
                        @if ($k['lampu'][\App\Services\PantauanStokService::LAMPU_MERAH] + $k['lampu'][\App\Services\PantauanStokService::LAMPU_KUNING] === 0)
                            <span class="num">{{ count($k['baris']) }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </nav>

        <section class="panel daftar-stok" id="daftar-stok">
            @foreach ($kelompok as $k)
                @php
                    $buka = $bukaSemua
                        || $k['lampu'][\App\Services\PantauanStokService::LAMPU_MERAH] + $k['lampu'][\App\Services\PantauanStokService::LAMPU_KUNING] > 0;
                @endphp
                <details class="kelompok-stok" data-kategori="{{ $k['kategori']->id }}" id="kategori-{{ $k['kategori']->id }}"{{ $buka ? ' open' : '' }}>
                    <summary>
                        <span class="kelompok-nama">{{ $k['kategori']->nama }}</span>
                        <x-ringkas-lampu class="kelompok-lampu" :jumlah="$k['lampu']" />
                    </summary>

                    <div class="tabel-bungkus">
                        <table @class(['tabel-kartu', 'tabel-stok', 'tanpa-ubah' => ! $aksiStok['ubah'] && $rute !== 'admin-utama.stok', 'tanpa-pilih' => ! $adaPilihan, 'ada-kosongkan' => $bisaKosongkan])>
                            <thead>
                                <tr>
                                    <th>Bahan</th>
                                    <th class="ka">Stok</th>
                                    <th class="ka">Minimum</th>
                                    <th>Status</th>
                                    <th class="ka"><span class="tersembunyi">Ubah</span></th>
                                    <th class="ka kolom-pilih">
                                        @if (collect($k['baris'])->contains($adaCentang))
                                            <label class="pilih-isi">
                                                <input type="checkbox" data-pilih-isi-semua aria-label="Pilih semua di {{ $k['kategori']->nama }}">
                                            </label>
                                        @else
                                            <span class="tersembunyi">Pilih</span>
                                        @endif
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($k['baris'] as $r)
                                    @php $b = $r['bahan']; @endphp
                                    <tr class="strip-lampu {{ \App\Services\PantauanStokService::kelasLampu($r['status']) }}" id="stok-baris-{{ $b->id }}" data-segar="baris"
                                        data-stok-baris data-lampu="{{ $r['lampu'] }}" data-cari="{{ mb_strtolower($b->nama) }}">
                                        <td class="kartu-judul" data-label="Bahan">
                                            <div class="dengan-foto">
                                                <x-foto :src="$b->urlFoto()" :nama="$b->nama" />
                                                <div class="dengan-foto-teks">
                                                    <a class="nama-barang tautan-baris" href="{{ route($rute.'.intip', [$b] + $parameterLokasi) }}" data-popup="panjang" data-popup-judul="{{ $b->nama }}">{{ $b->nama }}</a>
                                                    <span class="satuan stok-min-sempit">{{ $r['minimum'] > 0 ? 'Min. '.$keBeli($b, $r['minimum']) : 'Min. belum diatur' }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="ka num stok-nilai {{ $r['saldo'] < 0 ? 'teks-kurang' : '' }}" data-label="">
                                            {{ $keBeli($b, $r['saldo']) }}
                                            @if ($r['menunggu'] != 0)
                                                <div class="satuan stok-menunggu">{{ $r['menunggu'] > 0 ? '+' : '' }}{{ $keBeli($b, $r['menunggu']) }} menunggu</div>
                                            @endif
                                        </td>
                                        <td class="ka num hp-sembunyi" data-label="Minimum">
                                            @if ($r['minimum'] > 0)
                                                {{ $keBeli($b, $r['minimum']) }}
                                            @else
                                                <span class="satuan">Belum Diatur</span>
                                            @endif
                                        </td>
                                        <td class="hp-saja stok-meta satuan" data-label="">
                                            {{ $r['minimum'] > 0 ? 'Min. '.$keBeli($b, $r['minimum']) : 'Min. belum diatur' }}
                                        </td>
                                        <td class="stok-status" data-label=""><x-lampu-stok :status="$r['status']" /></td>
                                        <td @class(['ka', 'stok-ubah', 'hp-sembunyi' => ! $b->aktif || ! $aksiStok['ubah']]) data-label="">
                                            <div class="aksi-baris">
                                                @if ($b->aktif && $aksiStok['ubah'])
                                                    <x-master.tombol-ubah :href="route($rute.'.ubah', [$b] + $parameterLokasi)" :nama="'stok '.$b->nama" />
                                                @endif
                                                @if ($bisaKosongkan && $r['saldo'] != 0)
                                                    <a class="btn btn-sm tombol-ikon-aksi tombol-ikon-hapus" href="{{ route('admin-utama.stok.kosongkan', ['bahan' => $b->id] + $parameterLokasi) }}"
                                                       data-popup title="Kosongkan stok" aria-label="Kosongkan stok {{ $b->nama }}">
                                                        <x-ikon nama="sampah" width="18" height="18" />
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                        <td @class(['ka', 'stok-pilih', 'hp-sembunyi' => ! $adaPilihan]) data-label="">
                                            @if ($adaCentang($r))
                                                <label class="pilih-isi">
                                                    <input type="checkbox" value="{{ $b->id }}" data-pilih-isi aria-label="Pilih {{ $b->nama }}"
                                                           data-isi="{{ $bisaDipilih->has($b->id) ? 1 : 0 }}" data-kosong="{{ $bisaKosongkan && $r['saldo'] != 0 ? 1 : 0 }}"
                                                           data-perlu="{{ $r['lampu'] !== \App\Services\PantauanStokService::LAMPU_HIJAU ? 1 : 0 }}">
                                                </label>
                                            @elseif (array_key_exists($b->id, $pilihanIsi))
                                                <span class="badge b-jalan">Sudah Diminta</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach

            <x-kosong gambar="cari" data-stok-kosong :hidden="$kelompok->isNotEmpty()">Tidak ada bahan yang cocok.</x-kosong>

            @if ($adaPilihan)
                <div class="simpan-bar lekat bilah-isi-stok" data-isi-stok-bilah data-label="{{ $labelIsi }}" data-teks-kosong="{{ $teksKosong }}">
                    <span class="bilah-isi-stok-teks" data-isi-stok-teks aria-live="polite">{{ $teksKosong }}</span>
                    <div class="bilah-isi-stok-aksi">
                        <button type="button" class="btn" data-isi-stok-batal hidden>Batal Pilih</button>
                        @if ($bisaKosongkan)
                            <a class="btn btn-hapus" href="{{ route('admin-utama.stok.kosongkan', $parameterLokasi) }}" data-popup
                               data-dasar="{{ route('admin-utama.stok.kosongkan', $parameterLokasi) }}" data-kosong-tombol hidden>
                                <x-ikon nama="sampah" />
                                <span data-kosong-label>Kosongkan</span>
                            </a>
                        @endif
                        @if ($bisaDipilih->isNotEmpty())
                            <a class="btn btn-merah" href="{{ route($rute.'.isi', $parameterLokasi) }}" data-popup="panjang"
                               data-dasar="{{ route($rute.'.isi', $parameterLokasi) }}" data-isi-stok-tombol>
                                <x-ikon nama="plus" />
                                <span data-isi-stok-label>{{ $labelIsi }} Semua ({{ $jumlahPerlu }})</span>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection
