{{--
    Detail satu barang masuk. Variabel: $dokumen (BarangMasuk dengan detail, pencatat, persetujuan).
    Kiri: tabel barang. Kanan: batalkan (selama belum disetujui), keputusan Admin Utama. Atas: kartu kepala (nomor, status, info). Bawah kiri: kotak dampak.
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('pusat.barang-masuk') }}">Barang masuk</a>
@endsection
@section('judul', $dokumen->nomor)
@section('sub', $dokumen->tanggal->translatedFormat('l, j F Y'))

@section('aksi')
    @if ($dokumen->bisaDiubah())
        <a class="btn btn-ubah" href="{{ route('pusat.barang-masuk.ubah', $dokumen) }}">Ubah</a>
    @endif
@endsection

@section('content')
    <x-dokumen.kepala ikon="masuk" nada="masuk" jenis="Barang Masuk" :nomor="$dokumen->nomor" :ket="$dokumen->created_at ? 'dibuat '.$dokumen->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Lokasi'  => $dokumen->cabang->nama,
        'Tanggal' => $dokumen->tanggal->translatedFormat('j M Y'),
        'Dicatat' => $dokumen->pencatat->nama.' · '.$dokumen->created_at->translatedFormat('j M, H.i'),
        'Barang'  => $dokumen->detail->count().' bahan',
    ]">
        <x-slot:status>
            <span class="badge {{ $dokumen->kelas_status }}">{{ $dokumen->label_status }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Barang <span class="num">{{ $dokumen->detail->count() }}</span></h2>
                    @if ($dokumen->catatan)
                        <div class="sub">{{ $dokumen->catatan }}</div>
                    @endif
                </div>
            </div>
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-ringkas tabel-barang">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="ka">Jumlah</th>
                            <th class="ka">Satuan Resep</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dokumen->detail as $d)
                            @php $b = $d->bahanBaku; @endphp
                            <tr>
                                <td class="kartu-judul" data-label="Bahan">
                                    <div class="dengan-foto">
                                        <x-foto :src="$b->urlFoto()" :nama="$b->nama" />
                                        <div class="dengan-foto-teks">
                                            <span class="nama-barang">{{ $b->nama }}</span>
                                            @if ($d->keterangan)
                                                <span class="barang-ket">{{ $d->keterangan }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="ka num kartu-utama" data-label="">{{ \App\Support\Angka::tampil($b->keSatuanBeli($d->qty)) }} {{ $b->satuanBeli->nama }}</td>
                                <td class="ka num {{ (int) $b->satuan_beli_id === (int) $b->satuan_dasar_id ? 'hp-sembunyi' : '' }}" data-label="">
                                    {{ \App\Support\Angka::tampil($d->qty) }} {{ $b->satuanDasar->nama }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <x-dokumen.dampak>Setelah disetujui Admin Utama, stok gudang bertambah sesuai daftar dan tercatat di kartu stok tiap bahan. Selama menunggu, jumlahnya tampil sebagai "menunggu" di halaman Stok.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($dokumen->bisaDiubah())
                <section class="panel kotak-tindakan">
                    <form class="kotak-tindakan-isi" method="POST" action="{{ route('pusat.barang-masuk.batal', $dokumen) }}"
                          data-konfirmasi="{{ $dokumen->nomor }} dibatalkan. Stok tidak bertambah."
                          data-konfirmasi-judul="Batalkan Dokumen Ini?"
                          data-konfirmasi-tombol="Ya, Batalkan">
                        @csrf
                        @method('PATCH')
                        <p>Belum disetujui Admin Utama, masih bisa dibatalkan.</p>
                        <button type="submit" class="btn btn-bahaya">Batalkan</button>
                    </form>
                </section>
            @endif

            @if ($dokumen->persetujuan->isNotEmpty())
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan</h2></div>
                    <x-riwayat-keputusan :riwayat="$dokumen->persetujuan" />
                </section>
            @endif

            <x-dokumen.berkas :daftar="[[
                'judul' => 'Bukti barang masuk',
                'ket'   => 'Daftar barang yang diterima gudang',
                'url'   => route('pusat.barang-masuk.pdf', $dokumen),
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
