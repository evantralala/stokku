{{--
    Tempel Daftar (resources/js/tempel-daftar.js): tulisan bebas satu bahan per baris diubah menjadi baris form.
    Letakkan di dalam [data-baris-dinamis]. Prop: sumber (id <script> JSON daftar bahan).
--}}
@props(['sumber' => 'data-bahan'])

<details class="tempel-daftar" data-tempel-daftar data-sumber="{{ $sumber }}">
    <summary>Tempel Daftar</summary>
    <div class="tempel-daftar-isi">
        <label class="label-isian" for="tempel-{{ $sumber }}">Satu bahan per baris</label>
        <textarea class="isian" id="tempel-{{ $sumber }}" rows="5" data-tempel-teks
                  placeholder="beras 10 kg&#10;telor 2x30&#10;minyak goreng 18 liter"></textarea>
        <button type="button" class="btn" data-tempel-isi>Isi ke Daftar</button>
        <p class="satuan" data-tempel-hasil aria-live="polite"></p>
    </div>
</details>
