{{--
    Tiga angka lampu stok (habis · menipis · aman) di kepala halaman, lewat @section('lampu').
    Prop: jumlah ([lampu => angka], dari PantauanStokService::hitungPerLampu),
          rute (nama route halaman stok; tautan diberi ?lampu=) atau jangkar (tautan tetap, mis. '#ringkasan-lokasi').
--}}
@props(['jumlah', 'rute' => null, 'jangkar' => null])

<nav {{ $attributes->class('lampu-kepala') }} aria-label="Lampu stok">
    @foreach (\App\Services\PantauanStokService::TAMPILAN_LAMPU as $lampu => [$label, $kelas])
        <a class="lampu-kepala-item {{ $kelas }}" href="{{ $rute ? route($rute, ['lampu' => $lampu]) : $jangkar }}">
            <span class="lampu-kepala-angka num">{{ $jumlah[$lampu] ?? 0 }}</span>
            <span class="lampu-kepala-label"><span class="lampu-titik" aria-hidden="true"></span>{{ $label }}</span>
        </a>
    @endforeach
</nav>
