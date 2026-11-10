{{--
    Atur hak akses satu peran. Dibuka sebagai popup dari Pengaturan (bagian data-popup-isi),
    atau sebagai halaman biasa bila JavaScript mati. Variabel: $peran (potongan alamat), $labelPeran, $menu (tabelAkses), $labelAksi.
    Menu milik peran itu di tabel pertama (Lihat/Catat); menu Admin Utama yang bisa dibagikan di tabel kedua (satu sakelar per menu).
--}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route('admin-utama.pengaturan') }}">Pengaturan</a>
@endsection
@section('judul', 'Hak Akses '.$labelPeran)

@section('content')
    <form class="panel" method="POST" action="{{ route('admin-utama.kontrol-sistem.akses.simpan', $peran) }}" novalidate data-popup-isi>
        @csrf
        @method('PUT')

        @error('akses')
            <div class="kilat kilat-galat akses-galat" role="alert">
                <x-ikon nama="peringatan" />
                <p>{{ $message }}</p>
            </div>
        @enderror

        @php
            $menuSendiri = array_filter($menu, fn ($m) => $m['grup'] === null);
            $menuUtama = array_filter($menu, fn ($m) => $m['grup'] !== null);
            $centang = function (string $kunci, string $aksi, array $a, string $teks) {
                $nama = 'akses['.$kunci.']['.$aksi.']';

                return new \Illuminate\Support\HtmlString(
                    '<input type="hidden" name="'.e($nama).'" value="0">'
                    .'<label class="centang-akses"><input type="checkbox" name="'.e($nama).'" value="1"'
                    .((bool) old('akses.'.$kunci.'.'.$aksi, $a['boleh']) ? ' checked' : '').'>'
                    .'<span class="tersembunyi">'.e($teks).'</span></label>'
                );
            };
        @endphp

        <table class="tabel-akses">
            <thead>
                <tr>
                    <th scope="col">Menu</th>
                    @foreach ($labelAksi as $label)
                        <th scope="col" class="tg">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($menuSendiri as $kunci => $m)
                    <tr>
                        <th scope="row">{{ $m['label'] }}</th>
                        @foreach ($m['aksi'] as $aksi => $a)
                            <td class="tg">
                                @if (! $a['ada'])
                                    <span class="satuan" aria-label="Tidak ada">—</span>
                                @elseif ($a['kunci'])
                                    <span class="akses-kunci" title="Mata rantai stok, tidak bisa dimatikan">
                                        <x-ikon nama="kunci" width="14" height="14" />
                                        <span class="tersembunyi">{{ $labelAksi[$aksi] }} {{ $m['label'] }}: </span>Dikunci
                                    </span>
                                @else
                                    {{ $centang($kunci, $aksi, $a, $labelAksi[$aksi].' '.$m['label']) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="akses-catatan">Catat ikut mati bila Lihat dimatikan. Perubahan berlaku pada klik berikutnya pengguna.</p>

        @if ($menuUtama !== [])
            <table class="tabel-akses tabel-akses-utama">
                <thead>
                    <tr>
                        <th scope="col">{{ \App\Support\DaftarKontrol::GRUP_UTAMA }}</th>
                        <th scope="col" class="tg">Izinkan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($menuUtama as $kunci => $m)
                        <tr>
                            <th scope="row">{{ $m['label'] }}</th>
                            <td class="tg">{{ $centang($kunci, \App\Support\DaftarKontrol::AKSI_LIHAT, $m['aksi'][\App\Support\DaftarKontrol::AKSI_LIHAT], 'Izinkan '.$m['label']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p class="akses-catatan">
                Menu yang diizinkan muncul di menu {{ $labelPeran }} dan bisa dipakai penuh seperti Admin Utama.
                Pengaturan tetap khusus Admin Utama. Di menu Pengguna, akun Admin Utama tidak bisa diubah dan peran Admin Utama tidak bisa diberikan.
            </p>
        @endif

        <x-form.simpan :batal="route('admin-utama.pengaturan')" label="Simpan Hak Akses" />
    </form>
@endsection
