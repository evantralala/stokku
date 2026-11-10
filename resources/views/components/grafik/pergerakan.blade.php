{{--
    Grafik batang transaksi stok masuk dan keluar: per hari (Bulan Ini, Bulan Lalu) atau per bulan (Tahun Ini, Tahun Lalu),
    dipilih lewat ?gerak= di alamat. Prop: lokasi (Cabang, atau null = semua lokasi).
    Data dari App\Services\GrafikDasborService::pergerakan().
--}}
@props(['lokasi' => null])

@php
    $layanan = \App\Services\GrafikDasborService::class;
    $periode = $layanan::periode(request()->query('gerak'));
    $data = app($layanan)->pergerakan($lokasi, $periode);
    $totalMasuk = array_sum(array_column($data, 'masuk'));
    $totalKeluar = array_sum(array_column($data, 'keluar'));
@endphp

<section {{ $attributes->class(['panel', 'grafik-kartu']) }} id="grafik-pergerakan">
    <div class="panel-head">
        <div>
            <h2>Pergerakan Stok</h2>
            <div class="sub">Transaksi masuk dan keluar{{ $lokasi ? ' di '.$lokasi->nama : ' semua lokasi' }} · {{ $layanan::keterangan($periode) }}</div>
        </div>
        <div class="kanan grafik-legenda" aria-hidden="true">
            <span><i class="legenda-masuk"></i>Masuk</span>
            <span><i class="legenda-keluar"></i>Keluar</span>
        </div>
    </div>

    <x-grafik.periode nama="gerak" :terpilih="$periode" jangkar="grafik-pergerakan" />

    <div class="grafik-badan">
        <x-grafik.batang :data="$data" :seri="['masuk' => ['Masuk', 'batang-masuk'], 'keluar' => ['Keluar', 'batang-keluar']]"
                         :ringkas="'Transaksi '.$layanan::keterangan($periode).': '.$totalMasuk.' masuk, '.$totalKeluar.' keluar'"
                         judul-tabel="Transaksi stok masuk dan keluar" />
    </div>
</section>
