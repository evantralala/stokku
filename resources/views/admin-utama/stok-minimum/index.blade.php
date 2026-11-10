{{--
    Stok minimum per lokasi. Variabel dari StokMinimumController@index:
      $daftarLokasi, $lokasi (Cabang|null), $filter, $kelompok ([nama kategori => bahan]),
      $minimum ([bahan_baku_id => minimum dalam satuan dasar]), $jumlahTerisi, $jumlahBahan, $pilihanKategori
--}}
@extends('layouts.app')

@section('judul', 'Stok Minimum')
@section('sub', $lokasi ? $jumlahTerisi.' dari '.$jumlahBahan.' bahan terisi' : '')

@section('content')
    @if (! $lokasi)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Belum ada lokasi aktif. <a class="link" href="{{ route('admin-utama.lokasi.tambah') }}" data-popup>Tambah lokasi</a> terlebih dahulu.</p>
        </div>
    @elseif ($jumlahBahan === 0)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Belum ada bahan baku aktif. <a class="link" href="{{ route('admin-utama.bahan-baku.tambah') }}" data-popup>Tambah bahan baku</a> terlebih dahulu.</p>
        </div>
    @else
        <div class="kartu-grid kartu-grid-master">
            <x-kartu-angka ikon="ceklis" label="Bahan Terisi" :angka="$jumlahTerisi" nada="baik" :ket="'dari '.$jumlahBahan.' bahan aktif'" />
            <x-kartu-angka ikon="peringatan" label="Belum Diatur" :angka="$jumlahBahan - $jumlahTerisi"
                           :nada="$jumlahBahan - $jumlahTerisi > 0 ? 'perhatian' : 'baik'" ket="tanpa batas menipis" />
            <x-kartu-angka ikon="lokasi" label="Lokasi Aktif" :angka="$daftarLokasi->count()" :ket="'sedang dilihat: '.$lokasi->nama" />
        </div>

        <section class="panel">
            <div class="panel-head">
                <form class="alat alat-penuh" method="GET" action="{{ route('admin-utama.stok-minimum') }}">
                    <label class="tersembunyi" for="pilih-lokasi">Lokasi</label>
                    <select class="isian pilih-lokasi" id="pilih-lokasi" name="lokasi" data-kirim-otomatis>
                        @foreach ($daftarLokasi as $l)
                            <option value="{{ $l->id }}" @selected($l->id === $lokasi->id)>
                                {{ $l->nama }}{{ $l->isPusat() ? ' (Gudang Pusat)' : '' }}
                            </option>
                        @endforeach
                    </select>

                    <div class="kotak-cari">
                        <x-ikon nama="cari" width="18" height="18" />
                        <label class="tersembunyi" for="cari">Cari Bahan</label>
                        <input class="kotak-cari-isian" id="cari" type="search" name="cari" value="{{ $filter['cari'] }}"
                               placeholder="Cari bahan" enterkeyhint="search" autocomplete="off" data-cari-utama>
                    </div>

                    <label class="tersembunyi" for="filter-kategori">Kategori</label>
                    <select class="isian" id="filter-kategori" name="kategori" data-kirim-otomatis>
                        <option value="">Semua Kategori</option>
                        @foreach ($pilihanKategori as $id => $nama)
                            <option value="{{ $id }}" @selected($filter['kategori'] === $id)>{{ $nama }}</option>
                        @endforeach
                    </select>

                    <noscript><button type="submit" class="btn btn-sm">Tampilkan</button></noscript>
                </form>

                <div class="alat-master-aksi">
                    <x-tombol-riwayat jenis="stok-minimum" />
                    <x-master.tombol-excel master="stok-minimum" />
                </div>
            </div>

            <form method="POST" action="{{ route('admin-utama.stok-minimum.simpan') }}" novalidate>
                @csrf
                <input type="hidden" name="lokasi_id" value="{{ $lokasi->id }}">
                <input type="hidden" name="cari" value="{{ $filter['cari'] }}">
                <input type="hidden" name="kategori" value="{{ $filter['kategori'] }}">

                <p class="panduan">Kosong = tanpa batas. Di bawah angka ini bahan ditandai <strong>Menipis</strong>.</p>

                @forelse ($kelompok as $namaKategori => $daftarBahan)
                    <h3 class="grup-judul">{{ $namaKategori }}</h3>

                    <div class="form-grid daftar-minimum">
                        @foreach ($daftarBahan as $bahan)
                            @php $tersimpan = $minimum[$bahan->id] ?? null; @endphp
                            <x-isian-jumlah :name="'minimum['.$bahan->id.']'" :label="$bahan->nama" ringkas
                                            :value="$tersimpan ? \App\Support\Angka::isian($bahan->keSatuanBeli($tersimpan)) : ''"
                                            :satuan="$bahan->satuanBeli->nama" desimal maxlength="12" placeholder="0" />
                        @endforeach
                    </div>
                @empty
                    <x-kosong gambar="cari">
                        Tidak ada bahan yang cocok dengan pencarian.
                        <a class="link" href="{{ route('admin-utama.stok-minimum', ['lokasi' => $lokasi->id]) }}">Tampilkan semua</a>
                    </x-kosong>
                @endforelse

                @if ($kelompok->isNotEmpty())
                    <x-form.simpan :batal="route('admin-utama.stok-minimum', ['lokasi' => $lokasi->id])"
                                   :label="'Simpan'" />
                @endif
            </form>
        </section>

        @if ($daftarLokasi->count() > 1)
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Salin dari Lokasi Lain</h2>
                        <div class="sub">Cocok untuk cabang baru. Semua stok minimum {{ $lokasi->nama }} akan diganti.</div>
                    </div>
                </div>

                <form class="simpan-bar" method="POST" action="{{ route('admin-utama.stok-minimum.salin') }}"
                      data-konfirmasi="Semua stok minimum {{ $lokasi->nama }} akan diganti dengan milik lokasi yang dipilih."
                      data-konfirmasi-judul="Salin Stok Minimum?"
                      data-konfirmasi-tombol="Ya, Salin">
                    @csrf
                    <input type="hidden" name="ke_lokasi_id" value="{{ $lokasi->id }}">

                    <label class="tersembunyi" for="dari-lokasi">Salin dari</label>
                    <select class="isian salin-dari @error('dari_lokasi_id') galat @enderror" id="dari-lokasi" name="dari_lokasi_id">
                        <option value="">Pilih Lokasi Sumber</option>
                        @foreach ($daftarLokasi->where('id', '!==', $lokasi->id) as $l)
                            <option value="{{ $l->id }}">{{ $l->nama }}</option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn">Salin</button>

                    @error('dari_lokasi_id')
                        <span class="pesan-galat simpan-info">{{ $message }}</span>
                    @enderror
                </form>
            </section>
        @endif
    @endif
@endsection
