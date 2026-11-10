{{--
    Keputusan Admin Utama atas satu permintaan, per barang. Variabel dari PersetujuanController@permintaan:
      $permintaan, $saldoCabang, $saldoPusat ([bahan_baku_id => saldo dasar]), $pusat, $menunggu, $maksCatatan,
      $bisaKirim (masih ada barang disetujui yang belum terkirim dan Gudang Pusat aktif)
    Kiri: form keputusan (bila masih ada yang menunggu) dan tabel yang sudah diputuskan.
    Kanan: berkas PDF permintaan (semua barang dan statusnya) dan keterangan permintaan.
--}}
@extends('layouts.app')

@php
    $keBeli = fn ($bahan, $qty) => \App\Support\Angka::tampil($bahan->keSatuanBeli($qty)).' '.$bahan->satuanBeli->nama;
    $aksi = \App\Services\PersetujuanService::class;
    [$labelStatus, $kelasStatus] = $permintaan->tampilanStatus();
    $sudah = $permintaan->detail->where('status', '!=', \App\Models\PermintaanDetail::STATUS_MENUNGGU);
    $tautanKiriman = $permintaan->kiriman
        ->map(fn ($k) => '<a class="link" href="'.e(route('admin-utama.kiriman.lihat', $k)).'">'.e($k->nomor).'</a>')
        ->implode(', ');
@endphp

@section('crumb')
    <a class="link" href="{{ route('admin-utama.persetujuan') }}">Persetujuan</a>
@endsection
@section('judul', 'Permintaan '.$permintaan->cabang->nama)
@section('sub', $permintaan->nomor)

@section('aksi')
    @if ($bisaKirim)
        <a class="btn btn-merah" href="{{ route('admin-utama.kiriman.buat', $permintaan) }}">
            <x-ikon nama="truk" />
            Kirim Sekarang
        </a>
    @endif
@endsection

@section('content')
    <x-dokumen.kepala ikon="panah-kiri" nada="netral" jenis="Permintaan Barang" :nomor="$permintaan->nomor" :ket="$permintaan->diajukan_pada ? 'dibuat '.$permintaan->diajukan_pada->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Cabang'          => $permintaan->cabang->nama,
        'Diajukan'        => ($permintaan->otomatis() ? 'Otomatis' : $permintaan->pengaju?->nama).' · '.$permintaan->diajukan_pada?->translatedFormat('j M, H.i'),
        'Batas keputusan' => $menunggu->isNotEmpty() ? $permintaan->batas_keputusan?->translatedFormat('j M, H.i') : null,
        'Kiriman'         => $tautanKiriman !== '' ? new \Illuminate\Support\HtmlString($tautanKiriman) : null,
    ]">
        <x-slot:status>
            <span class="badge {{ $kelasStatus }}">{{ $labelStatus }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    @if ($permintaan->batas_keputusan && $menunggu->isNotEmpty())
        <div class="kilat {{ $permintaan->lewatBatas() ? 'kilat-galat' : 'kilat-info' }}" role="status">
            <x-ikon nama="peringatan" />
            <p>
                Putuskan sebelum {{ $permintaan->batas_keputusan->translatedFormat('j M, H.i') }}
                ({{ $permintaan->batas_keputusan->diffForHumans() }}).@modul('tolak_otomatis') Lewat batas, permintaan ditolak otomatis.@endmodul
            </p>
        </div>
    @endif

    <x-dokumen.tata>
        @if ($menunggu->isNotEmpty())
            <form class="panel" method="POST" action="{{ route('admin-utama.persetujuan.permintaan.putuskan', $permintaan) }}" novalidate
                  data-konfirmasi="Keputusan yang disimpan tidak bisa diubah lagi."
                  data-konfirmasi-judul="Simpan Keputusan?"
                  data-konfirmasi-tombol="Ya, Simpan">
                @csrf

                <div class="panel-head">
                    <div>
                        <h2>Perlu Diputuskan <span class="num">{{ $menunggu->count() }}</span></h2>
                        <div class="sub">Semua sudah terisi <strong>Setujui</strong> sesuai permintaan. Ubah yang perlu saja.</div>
                    </div>
                </div>

                @foreach ($menunggu as $d)
                    @php
                        $bahan = $d->bahanBaku;
                        $kunci = 'keputusan['.$d->id.']';
                        $stokPusat = $saldoPusat[$d->bahan_baku_id] ?? 0;
                        $stokCabang = (float) ($saldoCabang[$d->bahan_baku_id] ?? 0);
                        $aksiLama = old('keputusan.'.$d->id.'.aksi', $aksi::AKSI_SETUJUI);
                    @endphp

                    <fieldset class="keputusan-barang">
                        <legend class="nama-barang">
                            <span>{{ $bahan->nama }}</span>
                            <span class="num">Diminta {{ $keBeli($bahan, $d->qty_diminta) }}</span>
                        </legend>

                        <dl class="fakta-stok">
                            <div>
                                <dt>Stok Cabang</dt>
                                <dd class="num">
                                    {{ $keBeli($bahan, $d->sisa_saat_itu) }}
                                    @if (abs($stokCabang - (float) $d->sisa_saat_itu) > 0.0005)
                                        → {{ $keBeli($bahan, $stokCabang) }}
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Minimum</dt>
                                <dd class="num">{{ $keBeli($bahan, $d->minimum_saat_itu) }}</dd>
                            </div>
                            <div>
                                <dt>Stok {{ $pusat?->nama ?? 'Gudang Pusat' }}</dt>
                                <dd class="num {{ $stokPusat < (float) $d->qty_diminta ? 'teks-kurang' : '' }}">{{ $keBeli($bahan, $stokPusat) }}</dd>
                            </div>
                        </dl>
                        @if ($d->alasan)
                            <p class="fakta-alasan">“{{ $d->alasan }}”</p>
                        @endif

                        <div class="pilih-aksi" role="radiogroup" aria-label="Keputusan untuk {{ $bahan->nama }}">
                            @foreach ([$aksi::AKSI_SETUJUI => 'Setujui', $aksi::AKSI_TOLAK => 'Tolak', $aksi::AKSI_NANTI => 'Nanti'] as $nilai => $teks)
                                <label class="opsi-aksi opsi-{{ $nilai }}">
                                    <input type="radio" name="{{ $kunci }}[aksi]" value="{{ $nilai }}" @checked($aksiLama === $nilai)>
                                    <span>{{ $teks }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="form-grid isian-keputusan">
                            <div data-tampil-jika="{{ $kunci }}[aksi]:{{ $aksi::AKSI_SETUJUI }}">
                                <x-form.angka :name="$kunci.'[qty]'" label="Jumlah Disetujui" wajib desimal maxlength="12"
                                              :value="\App\Support\Angka::isian($bahan->keSatuanBeli($d->qty_diminta))"
                                              :satuan="$bahan->satuanBeli->nama" />
                            </div>
                            <x-form.teks :name="$kunci.'[catatan]'" label="Catatan" :maxlength="$maksCatatan"
                                         autocomplete="off" placeholder="Opsional" />
                        </div>
                    </fieldset>
                @endforeach

                <x-form.simpan :batal="route('admin-utama.persetujuan')" label="Simpan Keputusan" />
            </form>
        @endif

        @if ($sudah->isNotEmpty())
            <section class="panel">
                <div class="panel-head"><h2>Sudah Diputuskan <span class="num">{{ $sudah->count() }}</span></h2></div>
                <div class="tabel-bungkus">
                    <table class="tabel-kartu tabel-ringkas tabel-barang">
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th class="ka">Diminta</th>
                                <th class="ka">Disetujui</th>
                                <th class="ka">Dikirim</th>
                                <th>Keputusan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sudah as $d)
                                @php
                                    [$label, $kelas] = $d->tampilanStatus();
                                    $catatan = $d->persetujuan->sortByDesc('pada')->first()?->catatan;
                                @endphp
                                <tr>
                                    <td class="kartu-judul" data-label="Bahan">
                                        <div class="dengan-foto">
                                            <x-foto :src="$d->bahanBaku->urlFoto()" :nama="$d->bahanBaku->nama" />
                                            <div class="dengan-foto-teks">
                                                <span class="nama-barang">{{ $d->bahanBaku->nama }}</span>
                                                @if ($catatan)
                                                    <span class="barang-ket">{{ $catatan }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="ka num" data-label="Diminta">{{ $keBeli($d->bahanBaku, $d->qty_diminta) }}</td>
                                    <td class="ka num kartu-utama" data-label="">{{ $keBeli($d->bahanBaku, $d->qty_disetujui ?? 0) }}</td>
                                    <td class="ka num" data-label="Dikirim">{{ $keBeli($d->bahanBaku, $d->qty_dikirim ?? 0) }}</td>
                                    <td data-label=""><span class="badge {{ $kelas }}">{{ $label }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <x-dokumen.dampak>Permintaan tidak mengubah stok. Stok berpindah saat Gudang Pusat mengirim barang dan cabang menerimanya.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($pusat)
                <x-dokumen.berkas :daftar="[[
                    'judul' => 'Permintaan barang',
                    'ket'   => 'Semua barang beserta statusnya',
                    'url'   => route('admin-utama.kiriman.permintaan-pdf', $permintaan),
                ]]" />
            @endif

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
