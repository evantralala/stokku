{{-- Daftar notifikasi pengguna. Variabel dari NotifikasiController@index: $daftar, $belumDibaca. --}}
@extends('layouts.app')

@section('judul', 'Notifikasi')
@section('segar', 'ya')
@section('sub', $belumDibaca > 0 ? $belumDibaca.' belum dibaca' : 'Semua sudah dibaca')

@section('aksi')
    @if ($belumDibaca > 0)
        <form method="POST" action="{{ route('notifikasi.baca-semua') }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn">
                <x-ikon nama="ceklis" />
                Tandai Semua Dibaca
            </button>
        </form>
    @endif
@endsection

@section('content')
    <section class="panel">
        @forelse ($daftar as $n)
            <a class="notif {{ $n->sudahDibaca() ? '' : 'notif-baru' }}" href="{{ route('notifikasi.buka', $n) }}">
                <span class="notif-titik" aria-hidden="true"></span>
                <span class="notif-isi">
                    <span class="notif-judul">
                        @unless ($n->sudahDibaca())<span class="tersembunyi">Belum dibaca: </span>@endunless
                        {{ $n->judul }}
                    </span>
                    @if ($n->keterangan)
                        <span class="notif-ket">{{ $n->keterangan }}</span>
                    @endif
                    <span class="notif-waktu">{{ $n->created_at->diffForHumans() }}</span>
                </span>
                <x-ikon nama="panah-kanan" class="antrean-panah" />
            </a>
        @empty
            <x-kosong gambar="lonceng">Belum ada notifikasi.</x-kosong>
        @endforelse

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
