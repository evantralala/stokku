{{--
    Kiriman Gudang Pusat. Variabel dari Pusat\KirimanController@index (juga Admin Utama):
      $siapKirim, $kurang ([permintaan id => nama bahan yang stoknya kurang]), $kiriman, $lokasi, $rute (awalan route).
    Permintaan siap kirim bisa dicentang lalu dikirim sekaligus lewat bilah bawah, atau dibuka satu per satu.
--}}
@extends('layouts.app')

@section('judul', 'Kiriman')
@section('segar', 'ya')

@section('aksi')
    <x-tombol-unduh :href="route($rute.'.unduh')" periode />
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="ceklis" label="Siap Dikirim" :angka="$siapKirim->count()" :nada="$siapKirim->isNotEmpty() ? 'perhatian' : 'baik'" ket="permintaan disetujui" />
        <x-kartu-angka ikon="truk" label="Riwayat Kiriman" :angka="$kiriman->total()" ket="semua kiriman ke cabang" />
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>Siap Dikirim <span class="num">{{ $siapKirim->count() }}</span></h2>
            @if ($siapKirim->isNotEmpty())
                <div class="kanan">
                    <a class="btn btn-sm" href="{{ route($rute.'.siap-kirim-pdf') }}" download>
                        <x-ikon nama="masuk" width="18" height="18" />
                        Daftar Siap Kirim (PDF)
                    </a>
                </div>
            @endif
        </div>

        @if ($siapKirim->isEmpty())
            <x-kosong gambar="selesai">Semua sudah dikirim.</x-kosong>
        @else
            <div class="tabel-bungkus">
                <table class="tabel-kartu tabel-dokumen tabel-siap-kirim">
                    <thead>
                        <tr>
                            <th>Cabang</th>
                            <th>Diajukan</th>
                            <th class="ka">Barang</th>
                            <th><span class="tersembunyi">Aksi</span></th>
                            <th class="ka kolom-pilih"><x-pilih.semua form="pilih-kirim" label="semua permintaan" /></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($siapKirim as $p)
                            <tr>
                                <td class="kartu-judul" data-label="Cabang">
                                    <span class="nama-barang">{{ $p->cabang->nama }}</span>
                                    <span class="barang-ket num">{{ $p->nomor }}</span>
                                    @if ($kurang[$p->id] ?? [])
                                        <span class="barang-ket teks-kurang">Stok kurang: {{ implode(', ', $kurang[$p->id]) }}. Dikirim sebagian.</span>
                                    @endif
                                </td>
                                <td data-label="Diajukan">{{ $p->diajukan_pada->diffForHumans() }}</td>
                                <td class="ka num" data-label="Barang">{{ $p->utang_count }} barang</td>
                                <td class="ka kartu-aksi" data-label="">
                                    <a class="btn btn-sm btn-garis" href="{{ route($rute.'.buat', $p) }}">Kirim</a>
                                </td>
                                <td class="ka sel-pilih" data-label="">
                                    <x-pilih.kotak form="pilih-kirim" name="permintaan[]" :value="$p->id" :label="$p->nomor.' '.$p->cabang->nama" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pilih.bilah id="pilih-kirim" :action="route($rute.'.banyak.simpan')" tombol="Kirim yang Dicentang"
                           :konfirmasi="'Barang permintaan yang dicentang dikirim sesuai persetujuan dan stok '.$lokasi->nama.' langsung berkurang. Stok yang kurang dikirim sebagian.'"
                           judul-konfirmasi="Kirim yang Dicentang?" tombol-konfirmasi="Ya, Kirim" />
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Riwayat</h2></div>

        <x-tabel-dokumen :kolom="['Tujuan', 'Dikirim']">
            @foreach ($kiriman as $k)
                @php [$label, $kelas] = $k->tampilanStatus(); @endphp
                <x-baris-dokumen :href="route($rute.'.lihat', $k)" :nomor="$k->nomor"
                                 :sel="[$k->keCabang->nama, $k->dikirim_pada?->translatedFormat('j M, H.i')]"
                                 :label="$label" :kelas="$kelas" :jumlah="$k->detail_count.' barang'" />
            @endforeach
            <x-slot:kosong>Belum ada kiriman.</x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$kiriman" />
    </section>
@endsection
