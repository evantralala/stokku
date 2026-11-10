{{--
    Keputusan Admin Utama atas satu barang keluar. Variabel dari PersetujuanController@barangKeluar:
      $dokumen, $saldo ([bahan_baku_id => saldo dasar di lokasi]), $pilihanTolak, $maksCatatan
    Kiri: tabel barang. Kanan: kotak keputusan (bila menunggu), riwayat keputusan. Atas: kartu kepala (nomor, status, info). Bawah kiri: kotak dampak.
--}}
@extends('layouts.app')

@php $aksi = \App\Services\PersetujuanService::class; @endphp

@section('crumb')
    <a class="link" href="{{ route('admin-utama.persetujuan') }}">Persetujuan</a>
@endsection
@section('judul', 'Barang Rusak '.$dokumen->nomor)
@section('sub', $dokumen->cabang->nama.' · '.$dokumen->tanggal->translatedFormat('j M Y'))

@section('content')
    <x-dokumen.kepala ikon="keluar" nada="keluar" jenis="Barang Rusak" :nomor="$dokumen->nomor" :ket="$dokumen->created_at ? 'dibuat '.$dokumen->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Lokasi'  => $dokumen->cabang->nama,
        'Tanggal' => $dokumen->tanggal->translatedFormat('j M Y'),
        'Dicatat' => $dokumen->pencatat->nama,
    ]">
        <x-slot:status>
            <span class="badge {{ $dokumen->kelas_status }}">{{ $dokumen->label_status }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        <section class="panel">
            <div class="panel-head"><h2>Barang <span class="num">{{ $dokumen->detail->count() }}</span></h2></div>
            @include('barang-keluar._tabel', ['detail' => $dokumen->detail, 'saldo' => $saldo])
        </section>

        <x-dokumen.dampak>Setelah disetujui Admin Utama, stok lokasi ini berkurang sesuai daftar dan tercatat di kartu stok tiap bahan sebagai barang rusak.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($dokumen->status === \App\Models\BarangKeluar::STATUS_MENUNGGU)
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan</h2></div>
                    <form class="kotak-tindakan-isi" method="POST" action="{{ route('admin-utama.persetujuan.barang-keluar.putuskan', $dokumen) }}"
                          data-konfirmasi="Stok {{ $dokumen->cabang->nama }} akan berkurang sesuai daftar barang."
                          data-konfirmasi-judul="Setujui Barang Rusak?"
                          data-konfirmasi-tombol="Ya, Setujui">
                        @csrf
                        <input type="hidden" name="aksi" value="{{ $aksi::AKSI_SETUJUI }}">
                        <p>Alasannya wajar?</p>
                        <button type="submit" class="btn btn-merah">
                            <x-ikon nama="ceklis" />
                            Setujui
                        </button>
                    </form>

                    <details class="lipat-tolak"{{ $errors->any() ? ' open' : '' }}>
                        <summary>Tolak atau Kembalikan</summary>
                        <form method="POST" action="{{ route('admin-utama.persetujuan.barang-keluar.putuskan', $dokumen) }}" novalidate
                              data-konfirmasi="Stok tidak berubah. Pencatat akan melihat catatan Anda."
                              data-konfirmasi-judul="Kirim Keputusan?"
                              data-konfirmasi-tombol="Ya, Kirim">
                            @csrf
                            <div class="form-grid">
                                <x-form.pilihan name="aksi" label="Tindakan" :pilihan="$pilihanTolak" :value="$aksi::AKSI_REVISI" wajib />
                                <x-form.teks name="catatan" label="Alasan" wajib lebar :maxlength="$maksCatatan" autocomplete="off"
                                             placeholder="Mis. jumlahnya tidak sesuai" />
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
                'judul' => 'Berita acara barang rusak',
                'ket'   => 'Untuk ditandatangani pelapor dan Admin Utama',
                'url'   => route('admin-utama.persetujuan.barang-keluar.pdf', $dokumen),
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
