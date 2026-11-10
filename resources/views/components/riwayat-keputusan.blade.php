{{-- Daftar keputusan Admin Utama/sistem atas dokumen, terbaru di atas. Prop: riwayat (koleksi Persetujuan dengan relasi oleh). --}}
@props(['riwayat'])

<ul class="daftar-keputusan">
    @foreach ($riwayat->sortByDesc('pada') as $p)
        <li>
            <strong>{{ $p->labelKeputusan() }}</strong>
            <span class="satuan">· {{ $p->oleh?->nama ?? 'Sistem' }} · {{ $p->pada->translatedFormat('j M Y, H.i') }}</span>
            @if ($p->catatan)
                <div>{{ $p->catatan }}</div>
            @endif
        </li>
    @endforeach
</ul>
