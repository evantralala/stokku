{{--
    Kotak "Dokumen": daftar berkas (PDF atau Excel) yang bisa diunduh untuk dokumen ini.
    Prop: daftar (list of ['judul', 'ket', 'url', 'jenis'?]); jenis bawaan "PDF"; kotak tidak tampil bila kosong.
--}}
@props(['daftar' => []])

@if (count($daftar) > 0)
    <section {{ $attributes->class('panel kotak-berkas') }}>
        <div class="panel-head"><h2>Dokumen</h2></div>
        <ul class="daftar-berkas">
            @foreach ($daftar as $berkas)
                <li>
                    <a class="berkas" href="{{ $berkas['url'] }}" download>
                        <span class="berkas-tanda" aria-hidden="true">{{ $berkas['jenis'] ?? 'PDF' }}</span>
                        <span class="berkas-teks">
                            <span class="berkas-judul">{{ $berkas['judul'] }}</span>
                            @if (! empty($berkas['ket']))
                                <span class="berkas-ket">{{ $berkas['ket'] }}</span>
                            @endif
                        </span>
                        <x-ikon nama="masuk" class="berkas-unduh" width="18" height="18" />
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
