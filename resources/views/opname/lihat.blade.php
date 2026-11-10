{{--
    Hitung fisik opname (penerimaan buta: stok sistem tidak ditampilkan).
    Variabel dari KelolaOpname::show(): $opname, $detail (tanpa qty_sistem), $rute, $maksCatatan
    Saat dihitung: form hitung selebar halaman. Setelah dikirim: kiri tabel hasil hitung, kanan keputusan. Atas: kartu kepala (nomor, status, info).
--}}
@extends('layouts.app')

@php
    [$label, $kelas] = $opname->tampilanStatus();
    $sudah = $detail->filter->sudahDihitung()->count();
    $bolehHitung = app(\App\Services\KontrolSistemService::class)->boleh(auth()->user(), 'opname.catat');
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Stock opname</a>
@endsection
@section('judul', $opname->nomor)
@section('sub', $opname->tanggal->translatedFormat('l, j F Y').' · petugas '.$opname->petugas->nama)

@section('content')
    <x-dokumen.kepala ikon="ceklis" nada="netral" jenis="Stock Opname" :nomor="$opname->nomor" :ket="$opname->created_at ? 'dibuat '.$opname->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Tanggal' => $opname->tanggal->translatedFormat('j M Y'),
        'Petugas' => $opname->petugas->nama,
        'Dihitung' => $sudah.' dari '.$detail->count().' bahan',
    ]">
        <x-slot:status>
            <span class="badge {{ $kelas }}">{{ $label }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    @if ($opname->sedangDihitung() && $opname->persetujuan->isNotEmpty())
        <section class="panel">
            <div class="panel-head"><h2>Keputusan</h2></div>
            <x-riwayat-keputusan :riwayat="$opname->persetujuan" />
        </section>
    @endif

    @if ($opname->sedangDihitung() && ! $bolehHitung)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>Opname sedang dihitung. Anda hanya bisa melihat; penghitungan dilakukan petugas yang punya akses catat.</p>
        </div>
    @endif

    @if ($opname->sedangDihitung() && $bolehHitung)
        <form class="panel" method="POST" action="{{ route($rute.'.simpan', $opname) }}" novalidate data-opname>
            @csrf
            @method('PUT')

            <div class="panel-head opname-kepala">
                <div class="opname-progres" aria-live="polite">
                    <strong data-opname-sudah>{{ $sudah }}</strong> dari {{ $detail->count() }} bahan sudah dihitung
                </div>
                <div class="kotak-cari">
                    <x-ikon nama="cari" width="18" height="18" />
                    <label class="tersembunyi" for="cari-bahan-opname">Cari Bahan</label>
                    <input class="kotak-cari-isian" id="cari-bahan-opname" type="search" placeholder="Cari bahan"
                           autocomplete="off" data-opname-cari data-cari-utama>
                </div>
            </div>

            <p class="panduan">Habis? Isi <strong>0</strong>. Bisa dicicil dengan <strong>Simpan dulu</strong>.</p>

            @php $kategoriSebelumnya = null; @endphp
            @foreach ($detail as $d)
                @php $b = $d->bahanBaku; @endphp

                @if ($b->kategori->nama !== $kategoriSebelumnya)
                    <h3 class="opname-kategori" data-opname-judul>{{ $b->kategori->nama }}</h3>
                    @php $kategoriSebelumnya = $b->kategori->nama; @endphp
                @endif

                <div class="opname-baris" data-opname-baris data-cari="{{ \Illuminate\Support\Str::lower($b->nama) }}">
                    <div class="opname-nama">
                        <span class="nama-barang">{{ $b->nama }}</span>
                    </div>
                    <x-isian-jumlah bebas :name="'hitung['.$d->id.'][qty]'" label="Jumlah Fisik" desimal ringkas maxlength="24"
                                    :value="$d->sudahDihitung() ? \App\Support\Angka::isian($b->keSatuanBeli($d->qty_fisik)) : ''"
                                    :satuan="$b->satuanBeli->nama" placeholder="Belum" data-opname-qty />
                    <x-form.teks :name="'hitung['.$d->id.'][catatan]'" label="Catatan" :value="$d->catatan"
                                 :maxlength="$maksCatatan" autocomplete="off" placeholder="Opsional" />
                </div>
            @endforeach

            <div class="simpan-bar lekat">
                <div class="simpan-aksi">
                    <button type="submit" class="btn" name="aksi" value="{{ \App\Http\Requests\OpnameHitungRequest::AKSI_SIMPAN }}">
                        Simpan Dulu
                    </button>
                    <button type="submit" class="btn btn-merah" name="aksi" value="{{ \App\Http\Requests\OpnameHitungRequest::AKSI_KIRIM }}"
                            data-konfirmasi="Setelah dikirim, hasil hitung tidak bisa diubah kecuali Admin Utama meminta hitung ulang."
                            data-konfirmasi-judul="Kirim Hasil Opname ke Admin Utama?"
                            data-konfirmasi-tombol="Ya, Kirim">
                        <x-ikon nama="ceklis" />
                        Kirim ke Admin Utama
                    </button>
                </div>
            </div>
        </form>

        <section class="panel">
            <div class="simpan-bar">
                <span class="simpan-info">Salah mulai? Batalkan untuk membuka kunci stok.</span>
                <div class="simpan-aksi">
                    <form method="POST" action="{{ route($rute.'.batal', $opname) }}"
                          data-konfirmasi="Hasil hitung diabaikan dan stok tidak dikoreksi."
                          data-konfirmasi-judul="Batalkan Opname?"
                          data-konfirmasi-tombol="Ya, Batalkan">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-bahaya">Batalkan Opname</button>
                    </form>
                </div>
            </div>
        </section>
    @else
        <x-dokumen.tata>
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Hasil Hitung <span class="num">{{ $detail->count() }}</span></h2>
                        <div class="sub">Jumlah fisik</div>
                    </div>
                </div>
                <div class="tabel-bungkus">
                    <table class="tabel-kartu tabel-ringkas tabel-barang">
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th class="ka">Fisik</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($detail as $d)
                                @php $b = $d->bahanBaku; @endphp
                                <tr>
                                    <td class="kartu-judul" data-label="Bahan">
                                        <div class="dengan-foto">
                                            <x-foto :src="$b->urlFoto()" :nama="$b->nama" />
                                            <div class="dengan-foto-teks">
                                                <span class="nama-barang">{{ $b->nama }}</span>
                                                @if ($d->catatan)
                                                    <span class="barang-ket">{{ $d->catatan }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ka num kartu-utama" data-label="">{{ $d->sudahDihitung() ? \App\Support\Angka::tampil($b->keSatuanBeli($d->qty_fisik)).' '.$b->satuanBeli->nama : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <x-dokumen.dampak>Setelah disetujui Admin Utama, stok disamakan dengan hasil hitung fisik; selisihnya tercatat di kartu stok sebagai koreksi stock opname.</x-dokumen.dampak>

            <x-slot:samping>
                @if ($opname->persetujuan->isNotEmpty())
                    <section class="panel kotak-tindakan">
                        <div class="panel-head"><h2>Keputusan</h2></div>
                        <x-riwayat-keputusan :riwayat="$opname->persetujuan" />
                    </section>
                @endif

                <x-dokumen.berkas :daftar="[[
                    'judul' => 'Hasil stock opname',
                    'ket'   => $opname->status === \App\Models\Opname::STATUS_DISETUJUI ? 'Hitung fisik, stok sistem, dan selisih' : 'Hasil hitung fisik',
                    'url'   => route($rute.'.pdf', $opname),
                ], [
                    'judul' => 'Hasil stock opname',
                    'ket'   => 'Excel',
                    'url'   => route($rute.'.excel', $opname),
                    'jenis' => 'XLS',
                ]]" />

            </x-slot:samping>
        </x-dokumen.tata>
    @endif
@endsection
