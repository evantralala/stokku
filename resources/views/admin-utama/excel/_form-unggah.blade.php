{{--
    Isi popup unggah impor (dipakai Impor Excel per menu dan Impor Sekaligus): keterangan singkat, tombol templat,
    pilihan cara simpan (bila ada), pilih file, dan tombol cek.
    Parameter: $ket, $templat (alamat), $labelTemplat, $aksi (alamat pratinjau), $pilihanMode ([kunci => [label]]),
      $mode (bawaan), $labelMode, $terima (atribut accept), $teksFile,
      $pilihanTanggal ([Y-m-d => label], boleh kosong) dan $tanggal (terpilih): tanggal templat dan file yang diunggah.
--}}
@if (($pilihanTanggal ?? []) !== [])
    <fieldset class="impor-mode impor-tanggal" data-pilih-tanggal-impor>
        <legend class="label-isian">Tanggal</legend>
        <div class="pilih-segmen pilih-tanggal">
            @foreach ($pilihanTanggal as $kunci => $label)
                <label>
                    <input type="radio" name="tanggal" value="{{ $kunci }}" form="form-impor-unggah" @checked(old('tanggal', $tanggal) === $kunci)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif

<div class="impor-awal">
    <p class="impor-ket">{{ $ket }}</p>
    <a class="btn btn-sm" href="{{ ($pilihanTanggal ?? []) !== [] && $tanggal ? $templat.(str_contains($templat, '?') ? '&' : '?').'tanggal='.$tanggal : $templat }}" download data-templat-tanggal>
        <x-ikon nama="masuk" width="18" height="18" />
        {{ $labelTemplat }}
    </a>
</div>

<form class="impor-unggah" id="form-impor-unggah" method="POST" action="{{ $aksi }}" enctype="multipart/form-data" novalidate>
    @csrf
    @if ($pilihanMode !== [])
        <fieldset class="impor-mode">
            <legend class="label-isian">{{ $labelMode }}</legend>
            <div class="pilih-segmen">
                @foreach ($pilihanMode as $kunci => [$label])
                    <label>
                        <input type="radio" name="mode" value="{{ $kunci }}" @checked(old('mode', $mode) === $kunci)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif
    <label class="impor-file @error('file') galat @enderror" for="file-excel">
        <x-ikon nama="keluar" />
        <span class="impor-file-teks" data-nama-file>{{ $teksFile }}</span>
        <input class="tersembunyi" id="file-excel" type="file" name="file" required accept="{{ $terima }}"
               @error('file') aria-invalid="true" aria-describedby="file-excel-galat" @enderror>
    </label>
    @error('file')
        <span class="pesan-galat" id="file-excel-galat">{{ $message }}</span>
    @enderror
    <button type="submit" class="btn btn-gelap">
        <x-ikon nama="ceklis" />
        Cek Isi File
    </button>
</form>
