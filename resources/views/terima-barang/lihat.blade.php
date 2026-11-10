{{--
    Terima kiriman: lokasi tujuan mencocokkan barang yang tiba dengan data kiriman. Variabel dari KelolaTerimaBarang::show:
      $kiriman, $detail (KirimanDetail), $rute, $pilihanKondisi, $pilihanSesuai, $maksCatatan
    "Sesuai semua" = semua barang diterima sebanyak yang dikirim; "Ada yang beda" membuka isian per barang.
    Kiri: form penerimaan atau hasil hitung. Kanan: keterangan pengiriman (surat jalan dicetak dan dibawa pengirim).
--}}
@extends('layouts.app')

@php
    [$label, $kelas] = $kiriman->tampilanStatus();
    $keBeli = fn ($bahan, $qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
@endphp

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Terima barang</a>
@endsection
@section('judul', $kiriman->nomor)
@section('sub', 'Dikirim '.$kiriman->dikirim_pada?->translatedFormat('j M, H.i'))

@section('content')
    <x-dokumen.kepala ikon="masuk" nada="masuk" jenis="Terima Barang" :nomor="$kiriman->nomor" :ket="$kiriman->created_at ? 'dibuat '.$kiriman->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Dari'       => $kiriman->dariCabang?->nama,
        'Permintaan' => $kiriman->permintaan?->nomor ?? 'Tanpa permintaan',
        'Dikirim'    => $kiriman->dikirim_pada?->translatedFormat('j M, H.i'),
        'Pengirim'   => $kiriman->pengirim?->nama,
        'Diterima'   => $kiriman->sampai_pada?->translatedFormat('j M, H.i'),
        'Catatan'    => $kiriman->catatan_pusat,
    ]">
        <x-slot:status>
            <span class="badge {{ $kelas }}">{{ $label }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        @if ($kiriman->menungguDiterima())
            <form class="panel" method="POST" action="{{ route($rute.'.simpan', $kiriman) }}" novalidate data-popup-isi
                  data-konfirmasi="Barang masuk ke stok {{ $kiriman->keCabang?->nama ?? 'lokasi ini' }} dan tidak bisa diubah lagi."
                  data-konfirmasi-judul="Simpan Penerimaan?"
                  data-konfirmasi-tombol="Ya, Simpan">
                @csrf

                <p class="panduan">Cocokkan barang yang tiba dengan daftar ini.</p>

                <ul class="daftar-ringkas daftar-kiriman">
                    @foreach ($detail as $d)
                        <li>
                            <div class="daftar-ringkas-isi">
                                <span class="daftar-ringkas-kiri"><span class="nama-barang">{{ $d->bahanBaku->nama }}</span></span>
                                <span class="daftar-ringkas-kanan"><span class="num">{{ $keBeli($d->bahanBaku, $d->qty_kirim) }}</span></span>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="form-grid">
                    <x-form.pilih-kotak name="kesesuaian" label="Barang yang Tiba" :pilihan="$pilihanSesuai"
                                        :value="\App\Http\Requests\Cabang\TerimaKirimanRequest::SESUAI" wajib lebar />
                </div>

                <div data-tampil-jika="kesesuaian:{{ \App\Http\Requests\Cabang\TerimaKirimanRequest::TIDAK_SESUAI }}">
                    <p class="panduan">Isi jumlah yang <strong>layak pakai</strong> untuk setiap barang.</p>

                    @foreach ($detail as $d)
                        @php $kunci = 'terima['.$d->id.']'; @endphp

                        <fieldset class="keputusan-barang">
                            <legend class="nama-barang">{{ $d->bahanBaku->nama }}</legend>

                            <div class="isian-terima">
                                <x-isian-jumlah bebas :name="$kunci.'[qty]'" label="Layak Pakai" wajib desimal ringkas maxlength="24"
                                                :value="\App\Support\Angka::isian($d->bahanBaku->keSatuanBeli($d->qty_kirim))"
                                                :satuan="$d->bahanBaku->satuanBeli->nama" placeholder="0"
                                                :bantu="'Dikirim '.$keBeli($d->bahanBaku, $d->qty_kirim)" />

                                <x-form.pilih-kotak :name="$kunci.'[kondisi]'" label="Kondisi" :pilihan="$pilihanKondisi"
                                                    :value="\App\Models\KirimanDetail::KONDISI_BAIK" wajib lebar :kolom="4" />

                                <x-form.teks :name="$kunci.'[catatan]'" label="Catatan" :maxlength="$maksCatatan" autocomplete="off"
                                             placeholder="Wajib bila rusak, tidak ada, atau jumlahnya beda" lebar />
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                <x-form.simpan :batal="route($rute)" label="Simpan Penerimaan" />
            </form>
        @else
            <section class="panel">
                <div class="panel-head"><h2>Hasil Hitung <span class="num">{{ $detail->count() }}</span></h2></div>
                <div class="tabel-bungkus">
                    <table class="tabel-kartu tabel-ringkas tabel-barang">
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th class="ka">Dikirim</th>
                                <th class="ka">Diterima</th>
                                <th>Kondisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($detail as $d)
                                @php
                                    $b = $d->bahanBaku;
                                    $beda = ($d->selisih ?? 0) != 0;
                                @endphp
                                <tr>
                                    <td class="kartu-judul" data-label="Bahan">
                                        <div class="dengan-foto">
                                            <x-foto :src="$b->urlFoto()" :nama="$b->nama" />
                                            <div class="dengan-foto-teks">
                                                <span class="nama-barang">{{ $b->nama }}</span>
                                                @if ($d->catatan_penerima)
                                                    <span class="barang-ket">{{ $d->catatan_penerima }}</span>
                                                @endif
                                                @if ($beda)
                                                    <span class="barang-ket teks-kurang">Selisih {{ $keBeli($b, $d->selisih) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ka num {{ $beda ? '' : 'hp-sembunyi' }}" data-label="Dikirim">{{ $keBeli($b, $d->qty_kirim) }}</td>
                                    <td class="ka num kartu-utama" data-label="">{{ $keBeli($b, $d->qty_terima) }}</td>
                                    <td class="{{ $d->kondisi === \App\Models\KirimanDetail::KONDISI_BAIK ? 'hp-sembunyi' : '' }}" data-label="">
                                        {{ \App\Models\KirimanDetail::LABEL_KONDISI[$d->kondisi] ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <x-dokumen.dampak>Stok pengirim langsung berkurang saat barang dikirim. Stok tujuan bertambah sebanyak yang diterima; selisihnya tercatat otomatis.</x-dokumen.dampak>

        <x-slot:samping>
        </x-slot:samping>
    </x-dokumen.tata>
@endsection
