{{--
    Saringan laporan: periode, pilihan cepat, dan lokasi.
    Variabel: $jenis, $periode, $lokasi, $pilihanLokasi (null = laporan tanpa lokasi), $wajibLokasi,
              $kategori, $pilihanKategori (null = laporan tanpa saringan kategori bahan).
--}}
@php
    $rute = 'admin-utama.laporan.'.$jenis;
    $lokasiId = $lokasi?->id;
    $kategoriId = ($pilihanKategori ?? null) ? ($kategori?->id) : null;
@endphp

<form class="panel saring-laporan" method="GET" action="{{ route($rute) }}">
    <nav class="chip-saring chip-geser saring-periode" aria-label="Periode cepat">
        @foreach (\App\Support\Periode::pilihanCepat() as $label => [$dari, $sampai])
            <a class="chip" href="{{ route($rute, array_filter(['dari' => $dari, 'sampai' => $sampai, 'lokasi' => $lokasiId, 'kategori' => $kategoriId])) }}"{!! $periode->dariTanggal() === $dari && $periode->sampaiTanggal() === $sampai ? ' aria-current="true"' : '' !!}>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="form-grid saring-grid">
        <x-form.teks name="dari" label="Dari" type="date" :value="$periode->dariTanggal()" :max="now()->toDateString()" />
        <x-form.teks name="sampai" label="Sampai" type="date" :value="$periode->sampaiTanggal()" :max="now()->toDateString()" />

        @if ($pilihanLokasi)
            <div class="field lebar">
                <label for="saring-lokasi">Lokasi</label>
                <select class="isian" id="saring-lokasi" name="lokasi">
                    @unless ($wajibLokasi)
                        <option value="">Semua Lokasi</option>
                    @endunless
                    @foreach ($pilihanLokasi as $id => $nama)
                        <option value="{{ $id }}" @selected($lokasiId === $id)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($pilihanKategori ?? null)
            <div class="field lebar">
                <label for="saring-kategori">Kategori Bahan</label>
                <select class="isian" id="saring-kategori" name="kategori">
                    <option value="">Semua Kategori</option>
                    @foreach ($pilihanKategori as $id => $nama)
                        <option value="{{ $id }}" @selected($kategoriId === $id)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    <div class="simpan-bar">
        <div class="simpan-aksi">
            <a class="btn" href="{{ route('admin-utama.laporan.unduh', ['jenis' => $jenis] + array_filter([
                'dari' => $periode->dariTanggal(), 'sampai' => $periode->sampaiTanggal(), 'lokasi' => $lokasiId, 'kategori' => $kategoriId,
            ])) }}" download>
                <x-ikon nama="masuk" />
                Unduh Excel
            </a>
            <button type="submit" class="btn btn-merah">Tampilkan</button>
        </div>
    </div>
</form>
