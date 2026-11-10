{{--
    Pertanyaan "Maksudnya …?" di pratinjau impor. $tebakan: kunci, label, asli, saran, ada, baris (tempat munculnya).
--}}
@if ($tebakan !== [])
    <section class="impor-tebakan" aria-labelledby="judul-tebakan">
        <div class="impor-tebakan-kepala">
            <h2 id="judul-tebakan">Perlu Dicek ({{ count($tebakan) }})</h2>
            @if (count($tebakan) > 1)
                <button type="button" class="btn btn-sm" data-terima-semua hidden>Ya untuk Semua</button>
            @endif
        </div>
        <p class="satuan">Ada tulisan yang mirip nama di sistem. Pilih Ya bila maksudnya sama. Baris yang belum dijawab tidak disimpan.</p>

        @foreach ($tebakan as $t)
            <fieldset class="tebakan" data-tebakan="{{ $t['kunci'] }}">
                <legend class="tebakan-tanya">
                    {{ $t['label'] }} <q>{{ $t['asli'] }}</q>,
                    @if ($t['ada'])
                        mirip <strong>{{ $t['saran'] }}</strong> yang sudah ada. Maksudnya itu?
                    @else
                        maksudnya <strong>{{ $t['saran'] }}</strong>?
                    @endif
                </legend>
                <span class="satuan num">{{ $awalanBaris ?? 'Baris ' }}{{ implode(', ', $t['baris']) }}</span>
                <div class="tebakan-pilih">
                    <label class="tebakan-opsi">
                        <input type="radio" name="tebakan[{{ $t['kunci'] }}]" value="{{ \App\Services\Impor\PembersihImpor::TERIMA }}">
                        <span>Ya, {{ $t['saran'] }}</span>
                    </label>
                    <label class="tebakan-opsi">
                        <input type="radio" name="tebakan[{{ $t['kunci'] }}]" value="{{ \App\Services\Impor\PembersihImpor::TOLAK }}">
                        <span>{{ $t['ada'] ? 'Bukan, data baru' : 'Bukan' }}</span>
                    </label>
                </div>
            </fieldset>
        @endforeach
    </section>
@endif
