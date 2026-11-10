{{--
    Daftar baris pratinjau impor (dipakai pratinjau impor per menu dan Impor Sekaligus).
    $baris: baris hasil periksa (sudah diurutkan), $layanan: ImporExcel pemilik baris.
    Baris yang dilewati dilipat bila lebih dari 5.
--}}
@php
    $kelas = \App\Services\Impor\ImporExcel::class;
    $tampilStatus = [
        $kelas::STATUS_SALAH    => ['Salah Isi', 'b-tolak'],
        $kelas::STATUS_CEK      => ['Perlu Dicek', 'b-awas'],
        $kelas::STATUS_SIAP     => ['Siap', 'b-setuju'],
        $kelas::STATUS_DILEWATI => [$layanan->labelDilewati(), 'b-jalan'],
    ];
@endphp
@php
    $lipat = $baris->where('status', $kelas::STATUS_DILEWATI)->count() > 5;
    $kelompokBaris = $lipat ? $baris->partition(fn ($r) => $r['status'] !== $kelas::STATUS_DILEWATI) : collect([$baris, collect()]);
@endphp

@foreach ($kelompokBaris as $bagian => $daftarBaris)
    @if ($bagian === 1 && $daftarBaris->isNotEmpty())
        <details class="impor-lipat">
            <summary>Tampilkan {{ $daftarBaris->count() }} baris {{ mb_strtolower($layanan->labelDilewati()) }}</summary>
    @endif
    @foreach ($daftarBaris as $r)
    @php
        [$judulBaris, $ket] = $r['ringkas'] ?? $layanan->ringkasan($r['data']);
        $dibetulkanBaris = $r['status'] === $kelas::STATUS_SIAP && $r['betul'] !== [];
    @endphp
    <div class="antrean impor-baris" data-status="{{ $r['status'] }}"
         @if ($r['tunggu'] !== []) data-tunggu="{{ implode(' ', $r['tunggu']) }}" @endif>
        <div class="antrean-isi">
            <div class="antrean-atas">
                <span class="badge {{ $dibetulkanBaris ? 'b-aman' : $tampilStatus[$r['status']][1] }}">{{ $dibetulkanBaris ? 'Dibetulkan' : $tampilStatus[$r['status']][0] }}</span>
                <span class="satuan num">Baris {{ $r['baris'] }}</span>
            </div>
            <div class="antrean-judul">{{ $judulBaris !== '' ? $judulBaris : '(tanpa nama)' }}</div>
            @if ($ket !== '')
                <div class="antrean-ket">{{ $ket }}</div>
            @endif
            @foreach (collect($r['tebakan'])->whereNull('jawab') as $t)
                <div class="impor-tanya">{{ $t['label'] }} <q>{{ $t['asli'] }}</q>: maksudnya {{ $t['saran'] }}? Jawab di Perlu Dicek.</div>
            @endforeach
            @foreach ($r['status'] === $kelas::STATUS_CEK && collect($r['tebakan'])->whereNull('jawab')->isNotEmpty() ? [] : $r['pesan'] as $p)
                <div @class(['pesan-galat' => $r['status'] === $kelas::STATUS_SALAH, 'satuan' => $r['status'] !== $kelas::STATUS_SALAH])>{{ $p }}</div>
            @endforeach
            @if ($r['betul'] !== [])
                <div class="impor-betul">Dibetulkan: {{ implode(' · ', $r['betul']) }}</div>
            @endif
            @foreach ($r['catatan'] as $c)
                <div class="antrean-batas">{{ $c }}</div>
            @endforeach
        </div>
    </div>
    @endforeach
    @if ($bagian === 1 && $daftarBaris->isNotEmpty())
        </details>
    @endif
@endforeach
