{{-- Bagian atas setiap laporan: crumb, judul, sub (periode dan lokasi). Variabel: $judul, $periode, $lokasi, $pilihanLokasi. --}}
@section('crumb')
    <a class="link" href="{{ route('admin-utama.laporan') }}">Laporan</a>
@endsection
@section('judul', $judul)
@section('sub', $periode->label().($pilihanLokasi !== null ? ' · '.($lokasi?->nama ?? 'Semua Lokasi') : '').(($kategori ?? null) ? ' · '.$kategori->nama : ''))
