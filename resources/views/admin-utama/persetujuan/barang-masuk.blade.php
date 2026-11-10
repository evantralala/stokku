{{--
    Keputusan Admin Utama atas satu barang masuk. Variabel dari PersetujuanController@barangMasuk:
      $dokumen, $saldo ([bahan_baku_id => saldo dasar di lokasi]), $pilihanTolak, $maksCatatan
    Kiri: tabel barang. Kanan: kotak keputusan (bila menunggu), riwayat keputusan. Atas: kartu kepala (nomor, status, info). Bawah kiri: kotak dampak.
--}}
@extends('layouts.app')

@php
    $aksi = \App\Services\PersetujuanService::class;
    $keBeli = fn ($bahan, $qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
@endphp

@section('crumb')
    <a class="link" href="{{ route('admin-utama.persetujuan') }}">Persetujuan</a>
@endsection
@section('judul', 'Barang Masuk '.$dokumen->nomor)
@section('sub', $dokumen->cabang->nama.' · '.$dokumen->tanggal->translatedFormat('j M Y'))

@section('content')
    <x-dokumen.kepala ikon="masuk" nada="masuk" jenis="Barang Masuk" :nomor="$dokumen->nomor" :ket="$dokumen->created_at ? 'dibuat '.$dokumen->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Lokasi'  => $dokumen->cabang->nama,
        'Tanggal' => $dokumen->tanggal->translatedFormat('j M Y'),
        'Dicatat' => $dokumen->pencatat->nama.' · '.$dokumen->created_at->translatedFormat('j M, H.i'),
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
                            <th class="ka">Masuk</th>
                            <th class="ka">Stok Sekarang</th>
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
                                <td class="ka num kartu-utama" data-label="">+{{ $keBeli($b, $d->qty) }}</td>
                                <td class="ka num" data-label="Stok">{{ $keBeli($b, $saldo[$d->bahan_baku_id] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <x-dokumen.dampak>Setelah disetujui Admin Utama, stok lokasi ini bertambah sesuai daftar dan tercatat di kartu stok tiap bahan. Selama menunggu, jumlahnya tampil sebagai "menunggu" di halaman Stok.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($dokumen->status === \App\Models\BarangMasuk::STATUS_MENUNGGU)
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan</h2></div>
                    <form class="kotak-tindakan-isi" method="POST" action="{{ route('admin-utama.persetujuan.barang-masuk.putuskan', $dokumen) }}"
                          data-konfirmasi="Stok {{ $dokumen->cabang->nama }} akan bertambah sesuai daftar barang."
                          data-konfirmasi-judul="Setujui Barang Masuk?"
                          data-konfirmasi-tombol="Ya, Setujui">
                        @csrf
                        <input type="hidden" name="aksi" value="{{ $aksi::AKSI_SETUJUI }}">
                        <p>Sudah cocok dengan barang yang datang?</p>
                        <button type="submit" class="btn btn-merah">
                            <x-ikon nama="ceklis" />
                            Setujui
                        </button>
                    </form>

                    <details class="lipat-tolak"{{ $errors->any() ? ' open' : '' }}>
                        <summary>Tolak atau Kembalikan</summary>
                        <form method="POST" action="{{ route('admin-utama.persetujuan.barang-masuk.putuskan', $dokumen) }}" novalidate
                              data-konfirmasi="Stok tidak bertambah. Pencatat akan melihat catatan Anda."
                              data-konfirmasi-judul="Kirim Keputusan?"
                              data-konfirmasi-tombol="Ya, Kirim">
                            @csrf
                            <div class="form-grid">
                                <x-form.pilihan name="aksi" label="Tindakan" :pilihan="$pilihanTolak" :value="$aksi::AKSI_REVISI" wajib />
                                <x-form.teks name="catatan" label="Alasan" wajib lebar :maxlength="$maksCatatan" autocomplete="off"
                                             placeholder="Mis. jumlah nori seharusnya 2 kg, bukan 3 kg" />
                            </div>
                            <div class="simpan-bar">
                                <div class="simpan-aksi">
                                    <button type="submit" class="btn btn-bahaya">Kirim Keputusan</button>
                                </div>
                            </div>
                        </form>
                    </details>
                </section>
            @endif

            @if ($dokumen->persetujuan->isNotEmpty())
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan Sebelumnya</h2></div>
                    <x-riwayat-keputusan :riwayat="$dokumen->persetujuan" />
                </section>
            @endif

            <x-dokumen.berkas :daftar="[[
                'judul' => 'Bukti barang masuk',
                'ket'   => 'Daftar barang yang diterima gudang',
                'url'   => route('admin-utama.persetujuan.barang-masuk.pdf', $dokumen),
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
