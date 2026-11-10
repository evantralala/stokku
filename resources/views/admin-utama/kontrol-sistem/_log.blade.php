{{-- Daftar log perubahan, terbaru di atas. Variabel: $daftar (LogPerubahan dengan relasi oleh). --}}
<ul class="daftar-ringkas daftar-log">
    @forelse ($daftar as $log)
        <li>
            <div class="daftar-ringkas-isi">
                <span class="daftar-ringkas-kiri">
                    <span class="nama-barang">{{ $log->ringkasan }}</span>
                    <span class="satuan">
                        {{ $log->oleh?->nama ?? 'Sistem' }} · <time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->translatedFormat('j M Y, H.i') }}</time>
                    </span>

                    @if ($log->baris_rincian)
                        <details class="log-rincian">
                            <summary>Rincian</summary>
                            <dl>
                                @foreach ($log->baris_rincian as $r)
                                    <div>
                                        <dt>{{ $r['label'] }}</dt>
                                        <dd>
                                            @if ($r['lama'] !== null)
                                                <s>{{ $r['lama'] }}</s>
                                                <x-ikon nama="panah-kanan" width="14" height="14" />
                                            @endif
                                            {{ $r['baru'] ?? 'kosong' }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                </span>
                <span class="daftar-ringkas-kanan">
                    <span class="badge b-draf">{{ $log->label_kategori }}</span>
                </span>
            </div>
        </li>
    @empty
        <li><x-kosong gambar="dokumen" kecil>Belum ada perubahan yang tercatat.</x-kosong></li>
    @endforelse
</ul>
