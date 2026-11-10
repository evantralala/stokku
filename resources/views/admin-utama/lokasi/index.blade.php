{{-- Daftar lokasi (Gudang Pusat dan cabang). Variabel dari LokasiController. --}}
@extends('layouts.app')

@section('judul', 'Lokasi')
@section('sub', $jumlahAktif.' aktif, '.$jumlahNonaktif.' tidak aktif')

@section('content')
    <x-master.ringkas label="lokasi" ikon="lokasi" :aktif="$jumlahAktif" :nonaktif="$jumlahNonaktif">
        <x-kartu-angka ikon="rumah" label="Gudang Pusat" :angka="$adaPusatAktif ? 'Ada' : 'Belum'" :nada="$adaPusatAktif ? 'baik' : 'bahaya'"
                       :ket="$adaPusatAktif ? 'menerima barang masuk' : 'tambahkan Gudang Pusat'" />
    </x-master.ringkas>

    @unless ($adaPusatAktif)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Belum ada Gudang Pusat aktif. Tambahkan lokasi berjenis <strong>Gudang Pusat</strong>
               supaya barang masuk dan kiriman ke cabang bisa dicatat.</p>
        </div>
    @endunless

    <section class="panel">
        <div class="panel-head">
            <x-master.alat :rute="$rute" :filter="$filter" placeholder="Cari kode, nama, atau alamat">
                <label class="tersembunyi" for="filter-jenis">Jenis Lokasi</label>
                <select class="isian" id="filter-jenis" name="jenis" data-kirim-otomatis>
                    <option value="">Semua Jenis</option>
                    @foreach ($pilihanJenis as $nilai => $teks)
                        <option value="{{ $nilai }}" @selected($filter['jenis'] === $nilai)>{{ $teks }}</option>
                    @endforeach
                </select>

                <x-slot:aksi>
                    <x-tombol-riwayat jenis="lokasi" />
                    <x-master.tombol-excel master="lokasi" />
                    <a class="btn btn-merah btn-tambah" href="{{ route($rute.'.tambah') }}" data-popup="panjang">
                        <x-ikon nama="plus" />
                        Tambah Lokasi
                    </a>
                </x-slot:aksi>
            </x-master.alat>
        </div>

        <div class="tabel-bungkus">
            <table class="tabel-kartu tabel-ringkas">
                <thead>
                    <tr>
                        <th class="kolom-no">No</th>
                        <th>Lokasi</th>
                        <th>Jenis</th>
                        <th class="ka">Pengguna Aktif</th>
                        <th class="tg">Status</th>
                        <th class="ka"><span class="tersembunyi">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftar as $lokasi)
                        <tr>
                            <td class="kolom-no num hp-sembunyi" data-label="">{{ $daftar->firstItem() + $loop->index }}</td>
                            <td class="kartu-judul" data-label="Lokasi">
                                <div class="cabang">
                                    <span class="kode">{{ $lokasi->kode }}</span>
                                    <div>
                                        <a class="cabang-nama tautan-baris" href="{{ route($rute.'.ubah', $lokasi) }}" data-popup="panjang">{{ $lokasi->nama }}</a>
                                        @if ($lokasi->alamat)
                                            <div class="cabang-sub">{{ $lokasi->alamat }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="kartu-utama" data-label="Jenis">
                                <span class="badge {{ $lokasi->isPusat() ? 'b-pusat' : 'b-draf' }}">{{ $lokasi->label_jenis }}</span>
                            </td>

                            <td class="ka num" data-label="Pengguna">{{ $lokasi->jumlah_pengguna }}</td>
                            <td @class(['tg', 'hp-sembunyi' => $lokasi->aktif]) data-label=""><x-master.status :aktif="$lokasi->aktif" /></td>

                            <td class="ka kartu-aksi kartu-aksi-hp">
                                <div class="aksi-baris">
                                    <x-master.tombol-ubah :href="route($rute.'.ubah', $lokasi)" :nama="$lokasi->nama" popup="panjang" />
                                    <x-master.tombol-hapus :action="route($rute.'.hapus', $lokasi)" :nama="$lokasi->nama" label="lokasi" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-master.kosong popup="panjang" :kolom="6" :ada-data="$jumlahAktif + $jumlahNonaktif > 0" :rute="$rute" label="lokasi" />
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-master.halaman :daftar="$daftar" />
    </section>

    @if ($adaPusatAktif)
        <section class="panel" aria-labelledby="judul-pindah-pusat">
            <div class="panel-head">
                <div>
                    <h2 id="judul-pindah-pusat"><x-ikon-judul nama="truk" />Pindah Gudang Pusat</h2>
                    <div class="sub">Gudang pindah tempat? Stok, Admin Pusat, dan stok minimum dipindah ke lokasi baru sekaligus.</div>
                </div>
            </div>
            <div class="simpan-bar">
                <div class="simpan-aksi">
                    <a class="btn" href="{{ route('admin-utama.lokasi.pindah-pusat') }}" data-popup="panjang">
                        <x-ikon nama="truk" />
                        Pindah Gudang Pusat…
                    </a>
                </div>
            </div>
        </section>
    @endif
@endsection
