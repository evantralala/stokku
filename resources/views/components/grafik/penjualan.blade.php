{{--
    Grafik batang porsi terjual: per hari (Bulan Ini, Bulan Lalu) atau per bulan (Tahun Ini, Tahun Lalu), dipilih lewat ?jual=.
    Prop: lokasi (Cabang tetap, mis. dashboard cabang), atau pilihanLokasi ([id => nama]) supaya cabang bisa dipilih lewat
    ?jual_cabang= (kosong = semua cabang). Data dari App\Services\GrafikDasborService::penjualan().
    Selebar halaman, jadi digambar dua kali: gambar 640 untuk HP dan 960 untuk layar lebar (supaya tidak terlalu tinggi).
--}}
@props(['lokasi' => null, 'pilihanLokasi' => null])

@php
    $layanan = \App\Services\GrafikDasborService::class;
    $periode = $layanan::periode(request()->query('jual'));
    $dipilih = null;
    if ($lokasi === null && $pilihanLokasi !== null) {
        $id = (int) request()->query('jual_cabang');
        $dipilih = $pilihanLokasi->has($id) ? $id : null;
        $lokasi = $dipilih ? \App\Models\Cabang::find($dipilih) : null;
    }
    $data = app($layanan)->penjualan($lokasi, $periode);
    $total = array_sum(array_column($data, 'porsi'));
@endphp

<section {{ $attributes->class(['panel', 'grafik-kartu']) }} id="grafik-penjualan">
    <div class="panel-head">
        <div>
            <h2>Penjualan <span class="num">{{ \App\Support\Angka::tampil($total) }}</span> porsi</h2>
            <div class="sub">Porsi terjual{{ $lokasi ? ' di '.$lokasi->nama : ' semua cabang' }} · {{ $layanan::keterangan($periode) }}</div>
        </div>
        <div class="kanan grafik-legenda" aria-hidden="true">
            <span><i class="legenda-porsi"></i>Porsi</span>
        </div>
    </div>

    <x-grafik.periode nama="jual" :terpilih="$periode" jangkar="grafik-penjualan" />

    @if ($pilihanLokasi !== null && $pilihanLokasi->count() > 1)
        <nav class="chip-saring chip-geser grafik-periode" aria-label="Cabang grafik penjualan">
            <a class="chip" href="{{ request()->fullUrlWithQuery(['jual_cabang' => null]) }}#grafik-penjualan"{!! $dipilih === null ? ' aria-current="true"' : '' !!}>Semua Cabang</a>
            @foreach ($pilihanLokasi as $id => $nama)
                <a class="chip" href="{{ request()->fullUrlWithQuery(['jual_cabang' => $id]) }}#grafik-penjualan"{!! $dipilih === $id ? ' aria-current="true"' : '' !!}>{{ $nama }}</a>
            @endforeach
        </nav>
    @endif

    @foreach ([640 => 'grafik-sempit', 960 => 'grafik-luas'] as $lebar => $kelas)
        <div class="grafik-badan {{ $kelas }}">
            <x-grafik.batang :data="$data" :seri="['porsi' => ['Porsi', 'batang-porsi']]"
                             :ringkas="'Penjualan '.$layanan::keterangan($periode).': '.$total.' porsi'"
                             judul-tabel="Porsi terjual" :lebar="$lebar" />
        </div>
    @endforeach
</section>
