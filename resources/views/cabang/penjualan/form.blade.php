{{--
    Form catat / ubah penjualan harian. Variabel dari PenjualanController::form():
      $penjualan (null saat mencatat), $tanggal, $kelompok (menu per kategori menu), $porsiLama ([menu_id => porsi]),
      $tanpaResep (id menu tanpa resep berlaku), $hariIni, $batasAwal, $rute (awalan route: cabang.penjualan atau admin-utama.penjualan),
      $perkiraan (perkiraan stok dari porsi yang terisi; diperbarui otomatis oleh resources/js/penjualan.js)
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">{{ $rute === 'cabang.penjualan' ? 'Penjualan harian' : 'Catatan Penjualan' }}</a>
@endsection
@section('judul', $penjualan ? 'Ubah Penjualan' : 'Catat Penjualan')
@section('sub', \Illuminate\Support\Carbon::parse($tanggal)->translatedFormat('l, j F Y'))

@section('content')
    @if ($kelompok->isEmpty())
        <div class="kilat kilat-info" role="status" data-popup-isi>
            <x-ikon nama="peringatan" />
            <p>Belum ada menu aktif. Minta Admin Utama menambahkan menu dan resepnya.</p>
        </div>
    @else
        <form class="panel form-kasir" method="POST" novalidate data-penjualan data-popup-isi
              action="{{ $penjualan ? route($rute.'.perbarui', $penjualan) : route($rute.'.simpan') }}"
              data-konfirmasi="Bahan yang terpakai langsung dipotong dari stok cabang."
              data-konfirmasi-judul="Simpan Penjualan?"
              data-konfirmasi-tombol="Ya, Simpan">
            @csrf
            @if ($penjualan)
                @method('PUT')
                <input type="hidden" name="tanggal" value="{{ $tanggal }}">
            @endif

            <div class="panel-head penjualan-kepala">
                @unless ($penjualan)
                    <x-form.teks name="tanggal" label="Tanggal" type="date" wajib
                                 :value="$tanggal" :min="$batasAwal" :max="$hariIni" />
                @endunless
                <div class="kotak-cari">
                    <x-ikon nama="cari" width="18" height="18" />
                    <label class="tersembunyi" for="cari-menu">Cari Menu</label>
                    <input class="kotak-cari-isian" id="cari-menu" type="search" placeholder="Cari menu"
                           autocomplete="off" data-cari-menu data-cari-utama>
                </div>
                <div class="penjualan-total" aria-live="polite">
                    <strong class="num" data-penjualan-total>0</strong> porsi
                </div>
            </div>

            @foreach ($kelompok as $daftarMenu)
                <section class="kelompok-menu" data-menu-kelompok>
                    <h3 class="opname-kategori">{{ $daftarMenu->first()->kategoriMenu->nama }}</h3>

                    <div class="grid-menu">
                        @foreach ($daftarMenu as $m)
                            @php
                                $tanpa = in_array($m->id, $tanpaResep, true);
                                $nilai = old('porsi.'.$m->id, $porsiLama[$m->id] ?? '');
                                $kunci = $tanpa && ($nilai === '' || $nilai === null);
                            @endphp
                            <div @class(['kartu-menu', 'tanpa-resep' => $tanpa]) data-menu-kartu data-cari="{{ \Illuminate\Support\Str::lower($m->nama) }}">
                                <span class="kartu-menu-kepala">
                                    <x-foto :src="$m->urlFoto()" :nama="$m->nama" />
                                    <span class="kartu-menu-nama">{{ $m->nama }}</span>
                                </span>
                                @if ($tanpa)
                                    <span class="kartu-menu-ket">Belum Ada Resep</span>
                                @endif
                                <x-isian-jumlah :name="'porsi['.$m->id.']'" :label="$m->nama" label-tersembunyi ringkas
                                                :value="$nilai" maxlength="4" placeholder="0" data-penjualan-porsi
                                                :disabled="$kunci" />
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <section class="perkiraan-wadah" aria-live="polite" data-perkiraan
                     data-perkiraan-url="{{ route($rute.'.perkiraan', $penjualan ? ['penjualan' => $penjualan->id] : []) }}">
                @include('stok._perkiraan', ['perkiraan' => $perkiraan])
            </section>

            <x-form.simpan :batal="$penjualan ? route($rute.'.lihat', $penjualan) : route($rute)"
                           :label="$penjualan ? 'Simpan Perubahan' : 'Simpan Penjualan'" />
        </form>
    @endif
@endsection
