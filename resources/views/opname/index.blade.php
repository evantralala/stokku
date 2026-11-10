{{-- Daftar stock opname satu lokasi. Variabel dari KelolaOpname::index(): $daftar, $rute, $lokasi, $berjalan. --}}
@extends('layouts.app')

@section('segar', 'ya')

@section('judul', 'Opname')
@section('sub', $lokasi->nama)

@section('aksi')
    @boleh('opname.catat')
        @unless ($berjalan)
            <x-master.tombol-excel :impor="route($rute.'.impor')" label="opname" />
            <form method="POST" action="{{ route($rute.'.mulai') }}"
                  data-konfirmasi="Stok {{ $lokasi->nama }} dikunci sampai Admin Utama memutuskan hasilnya. Kiriman dan persetujuan untuk lokasi ini ditahan dulu."
                  data-konfirmasi-judul="Mulai Opname?"
                  data-konfirmasi-tombol="Ya, Mulai">
                @csrf
                <button type="submit" class="btn btn-merah">
                    <x-ikon nama="plus" />
                    Mulai Opname
                </button>
            </form>
        @endunless
    @endboleh
@endsection

@section('content')
    <div class="kartu-grid kartu-grid-master">
        <x-kartu-angka ikon="ceklis" label="Opname" :angka="$daftar->total()" :ket="'tercatat di '.$lokasi->nama" />
        <x-kartu-angka ikon="kunci" label="Sedang Berjalan" :angka="$berjalan ? 'Ya' : 'Tidak'" :nada="$berjalan ? 'perhatian' : 'baik'"
                       :ket="$berjalan ? 'stok dikunci: '.$berjalan->nomor : 'stok bisa dipakai'" />
    </div>

    @if ($berjalan)
        <div class="kilat kilat-info" role="status">
            <x-ikon nama="peringatan" />
            <p>
                {{ $berjalan->sedangDihitung() ? 'Opname sedang dihitung.' : 'Opname menunggu keputusan Admin Utama.' }} Stok dikunci.
                <a class="link" href="{{ route($rute.'.lihat', $berjalan) }}">{{ $berjalan->sedangDihitung() ? 'Lanjut hitung' : 'Lihat' }}</a>
            </p>
        </div>
    @endif

    <section class="panel">
        <x-tabel-dokumen :kolom="['Tanggal', 'Petugas']" jumlah="Bahan">
            @foreach ($daftar as $o)
                @php [$label, $kelas] = $o->tampilanStatus(); @endphp
                <x-baris-dokumen :href="route($rute.'.lihat', $o)" :nomor="$o->nomor"
                                 :sel="[$o->tanggal->translatedFormat('j M Y'), $o->petugas->nama]"
                                 :label="$label" :kelas="$kelas" :jumlah="$o->detail_count.' bahan'" />
            @endforeach
            <x-slot:kosong>Belum pernah opname di sini.</x-slot:kosong>
        </x-tabel-dokumen>

        <x-master.halaman :daftar="$daftar" />
    </section>
@endsection
