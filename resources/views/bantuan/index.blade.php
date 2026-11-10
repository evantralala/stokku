{{--
    Bantuan sesuai peran. Variabel dari BantuanController: $topik (BantuanService::untuk), $dipilih (kunci topik atau null), $cari.
    Topik terpilih terbuka dan isinya ditandai data-popup-isi, sehingga tombol "?" di kepala halaman
    membuka topik itu sebagai popup. Kotak cari menyaring langsung (resources/js/saring-bantuan.js), tanpa JS lewat ?cari=.
--}}
@extends('layouts.app')

@section('judul', 'Bantuan')

@section('alat')
    <form class="alat-stok-form" method="GET" action="{{ route('bantuan') }}">
        <div class="kotak-cari">
            <x-ikon nama="cari" width="18" height="18" />
            <label class="tersembunyi" for="cari-bantuan">Cari Bantuan</label>
            <input class="kotak-cari-isian" id="cari-bantuan" type="search" name="cari" value="{{ $cari }}"
                   placeholder="Cari, mis. opname" autocomplete="off" enterkeyhint="search" data-cari-utama data-cari-bantuan>
        </div>
    </form>
@endsection

@section('content')
    <div class="bantuan" data-saring-bantuan>
        @foreach ($topik as $kunci => $t)
            <details class="panel bantuan-topik" id="bantuan-{{ $kunci }}"
                     data-cari="{{ mb_strtolower(implode(' ', [$t['judul'], $t['ringkas'], ...$t['langkah'], ...$t['tips']])) }}"{{ $kunci === $dipilih || $cari !== '' ? ' open' : '' }}>
                <summary>
                    <span class="bantuan-ikon" aria-hidden="true"><x-ikon :nama="$t['ikon']" /></span>
                    <span class="bantuan-judul">
                        <span class="nama-barang">{{ $t['judul'] }}</span>
                        <span class="satuan">{{ $t['ringkas'] }}</span>
                    </span>
                </summary>

                <div class="bantuan-isi"{!! $kunci === $dipilih ? ' data-popup-isi' : '' !!}>
                    <ol class="bantuan-langkah">
                        @foreach ($t['langkah'] as $l)
                            <li>{{ $l }}</li>
                        @endforeach
                    </ol>

                    @if ($t['tips'] !== [])
                        <ul class="bantuan-tips">
                            @foreach ($t['tips'] as $tip)
                                <li>{{ $tip }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="bantuan-aksi">
                        @if ($kunci === $dipilih)
                            <a class="btn" href="{{ route('bantuan') }}">Semua Bantuan</a>
                        @elseif ($t['rute'] && \Illuminate\Support\Facades\Route::has($t['rute']))
                            <a class="btn" href="{{ route($t['rute']) }}">
                                Buka {{ $t['judul'] }}
                                <x-ikon nama="panah-kanan" width="18" height="18" />
                            </a>
                        @endif
                    </div>
                </div>
            </details>
        @endforeach

        <x-kosong gambar="cari" data-bantuan-kosong :hidden="$topik !== []">
            Tidak ada topik yang cocok. <a class="link" href="{{ route('bantuan') }}">Lihat semua</a>
        </x-kosong>
    </div>
@endsection
