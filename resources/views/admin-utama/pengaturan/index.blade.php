{{--
    Pengaturan. Variabel dari PengaturanController@index:
    $daftar (Pengaturan umum), $akunPeran (pengguna per role), $labelPeran,
    $modul (KontrolSistemService::daftarModul), $akses ([potongan peran => [label, menu (tabelAkses)]]),
    $periodeMulai (awal periode buku berjalan, Carbon|null), $periodeArsip (PeriodeBuku yang sudah ditutup, terbaru dulu),
    $periodeSiap (tabel periode_buku sudah ada), $identitas (IdentitasUsahaService::data), $logoUnggahan (bool), $maksIdentitas (batas panjang isian).
    Semua bagian untuk pemakaian sehari-hari, ditulis dengan bahasa awam.
--}}
@extends('layouts.app')

@section('judul', 'Pengaturan')
@section('sub', 'Aturan umum, identitas usaha, fitur, akun, dan periode buku')

@section('aksi')
    <x-tombol-riwayat jenis="pengaturan" />
@endsection

@section('alat')
    <nav class="chip-saring chip-geser" aria-label="Bagian pengaturan">
        <a class="chip" href="#umum">Umum</a>
        <a class="chip" href="#identitas">Identitas</a>
        <a class="chip" href="#fitur">Fitur</a>
        <a class="chip" href="#akun">Akun</a>
        <a class="chip" href="#akses">Hak Akses</a>
        <a class="chip" href="#periode">Periode</a>
    </nav>
@endsection

@section('content')
    <div class="atur-grid">
        <section class="panel atur-penuh" id="umum" aria-labelledby="judul-umum">
            <div class="panel-head">
                <div>
                    <h2 id="judul-umum"><x-ikon-judul nama="gear" />Umum</h2>
                    <div class="sub">Angka aturan persetujuan, selisih, dan batas waktu</div>
                </div>
            </div>

            @if ($daftar->isEmpty())
                <div class="kilat kilat-info" role="status">
                    <x-ikon nama="peringatan" />
                    <p>Belum ada pengaturan. Jalankan <strong>php artisan db:seed --class=PengaturanSeeder</strong>.</p>
                </div>
            @else
                <form method="POST" action="{{ route('admin-utama.pengaturan.simpan') }}" novalidate>
                    @csrf
                    @method('PUT')

                    <div class="form-grid">
                        @foreach ($daftar as $p)
                            @php
                                $nama = 'nilai['.$p->kunci.']';
                                $label = $p->label ?? $p->kunci;
                                $bantu = trim($p->keterangan.' '.($p->teksRentang() ? '('.$p->teksRentang().')' : ''));
                            @endphp

                            @switch ($p->tipe)
                                @case (\App\Models\Pengaturan::TIPE_BILANGAN)
                                    <x-isian-jumlah :name="$nama" :label="$label" :value="$p->nilai" :satuan="$p->satuan ?? ''"
                                                    :bantu="$bantu" wajib maxlength="6" :min="(float) ($p->nilai_min ?? 0)"
                                                    :maks="$p->nilai_maks !== null ? (float) $p->nilai_maks : null" />
                                    @break

                                @case (\App\Models\Pengaturan::TIPE_PERSEN)
                                    <x-isian-jumlah :name="$nama" :label="$label" :value="\App\Support\Angka::isian($p->nilai, 2)"
                                                    :satuan="$p->satuan ?? '%'" :bantu="$bantu" wajib desimal maxlength="7" :min="(float) ($p->nilai_min ?? 0)"
                                                    :maks="$p->nilai_maks !== null ? (float) $p->nilai_maks : null" />
                                    @break

                                @case (\App\Models\Pengaturan::TIPE_JAM)
                                    <x-form.teks :name="$nama" :label="$label" type="time" :value="$p->nilai"
                                                 :bantu="$bantu" wajib />
                                    @break

                                @default
                                    <x-form.teks :name="$nama" :label="$label" :value="$p->nilai" :bantu="$bantu"
                                                 wajib lebar maxlength="255" />
                            @endswitch
                        @endforeach
                    </div>

                    <x-form.simpan :batal="route('admin-utama.pengaturan')" label="Simpan Pengaturan" />
                </form>
            @endif
        </section>

        <section class="panel atur-penuh" id="identitas" aria-labelledby="judul-identitas">
            <div class="panel-head">
                <div>
                    <h2 id="judul-identitas"><x-ikon-judul nama="label" />Identitas Usaha</h2>
                    <div class="sub">Nama dan logo yang tampil di aplikasi, dokumen cetak, Excel, dan ikon HP</div>
                </div>
            </div>

            <form method="POST" action="{{ route('admin-utama.pengaturan.identitas') }}" enctype="multipart/form-data" novalidate>
                @csrf
                @method('PUT')

                <div class="form-grid">
                    <x-form.teks name="nama_usaha" label="Nama Usaha" :value="$identitas['nama_usaha']" wajib
                                 :maxlength="$maksIdentitas['nama']" bantu="Mis. di kaki halaman masuk dan dokumen." />
                    <x-form.teks name="nama_aplikasi" label="Nama Aplikasi" :value="$identitas['nama_aplikasi']" wajib
                                 :maxlength="$maksIdentitas['nama']" bantu="Tampil di menu samping dan judul tab browser." />
                    <x-form.teks name="nama_singkat" label="Nama Singkat" :value="$identitas['nama_singkat']" wajib
                                 :maxlength="$maksIdentitas['singkat']" bantu="Di bawah ikon aplikasi di HP, maks. {{ $maksIdentitas['singkat'] }} huruf." />
                    <x-form.teks name="kota" label="Kota" :value="$identitas['kota']"
                                 :maxlength="$maksIdentitas['kota']" bantu="Boleh kosong." />

                    @php $isianLogo = \App\View\Isian::dari('logo', null, null, $errors); @endphp
                    <div class="field lebar isian-foto" data-isian-foto>
                        <span class="label-isian">Logo</span>
                        <div class="isian-foto-baris">
                            <span class="foto foto-sedang logo-pratinjau" data-foto-pratinjau>
                                <img src="{{ \App\Support\Identitas::logo() }}" alt="Logo sekarang">
                            </span>
                            <div class="isian-foto-kendali">
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="isian-foto-file"
                                       id="{{ $isianLogo->id }}" aria-describedby="bantu-logo{{ $isianLogo->adaGalat() ? ' '.$isianLogo->idGalat : '' }}"
                                       @if ($isianLogo->adaGalat()) aria-invalid="true" @endif>
                                <label class="btn btn-sm" for="{{ $isianLogo->id }}">
                                    <x-ikon nama="kamera" />
                                    <span data-foto-teks>Ganti Logo</span>
                                </label>
                                @if ($logoUnggahan)
                                    <label class="isian-foto-hapus">
                                        <input type="checkbox" name="logo_bawaan" value="1" data-foto-hapus @checked(old('logo_bawaan'))>
                                        Pakai Logo Bawaan
                                    </label>
                                @endif
                            </div>
                        </div>
                        <span class="bantu" id="bantu-logo">PNG dengan latar transparan paling bagus. Ikon HP dibuat otomatis dengan latar putih.</span>
                        @if ($isianLogo->adaGalat())
                            <span class="pesan-galat" id="{{ $isianLogo->idGalat }}">{{ $isianLogo->pesanGalat }}</span>
                        @endif
                    </div>
                </div>

                <x-form.simpan :batal="route('admin-utama.pengaturan')" label="Simpan Identitas" />
            </form>
        </section>

        <section class="panel kontrol-modul" id="fitur" aria-labelledby="judul-modul">
            <div class="panel-head">
                <div>
                    <h2 id="judul-modul"><x-ikon-judul nama="sakelar" />Fitur</h2>
                    <div class="sub">Modul yang bisa dinyalakan atau dimatikan</div>
                </div>
            </div>

            <ul class="daftar-ringkas">
                @foreach ($modul as $kunci => $m)
                    <li>
                        <div class="daftar-ringkas-isi">
                            <span class="daftar-ringkas-kiri">
                                <span class="nama-barang">{{ $m['label'] }}</span>
                                <span class="satuan">{{ $m['keterangan'] }}</span>
                            </span>
                            <span class="daftar-ringkas-kanan">
                                <form method="POST" action="{{ route('admin-utama.kontrol-sistem.modul', $kunci) }}"
                                      @if ($m['nyala'])
                                          data-konfirmasi="{{ $m['efek_mati'] }}"
                                          data-konfirmasi-judul="Matikan modul {{ $m['label'] }}?"
                                          data-konfirmasi-tombol="Ya, Matikan"
                                      @endif>
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="nyala" value="{{ $m['nyala'] ? 0 : 1 }}">
                                    <button type="submit" class="sakelar" role="switch"
                                            aria-checked="{{ $m['nyala'] ? 'true' : 'false' }}"
                                            aria-label="Modul {{ $m['label'] }}">
                                        <span class="sakelar-teks" aria-hidden="true">{{ $m['nyala'] ? 'Nyala' : 'Mati' }}</span>
                                        <span class="sakelar-jalur" aria-hidden="true"></span>
                                    </button>
                                </form>
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="panel kontrol-akun" id="akun" aria-labelledby="judul-akun">
            <div class="panel-head">
                <h2 id="judul-akun"><x-ikon-judul nama="pengguna" />Akun Pengguna</h2>
                <a class="link kanan" href="{{ route('admin-utama.pengguna') }}">Kelola</a>
            </div>

            @foreach ($labelPeran as $role => $label)
                @continue(! $akunPeran->has($role))

                <h3 class="kontrol-subjudul">{{ $label }}</h3>
                <ul class="daftar-ringkas">
                    @foreach ($akunPeran[$role] as $u)
                        <li>
                            <div class="daftar-ringkas-isi">
                                <span class="daftar-ringkas-kiri">
                                    <span class="nama-barang">
                                        {{ $u->nama }}
                                        @if ($u->is($pengguna))
                                            <span class="badge b-jalan">Anda</span>
                                        @endif
                                    </span>
                                    <span class="satuan">{{ $u->cabang?->nama ?? 'Semua Lokasi' }}</span>
                                </span>

                                <span class="daftar-ringkas-kanan">
                                    @if ($u->is($pengguna))
                                        <x-master.status :aktif="$u->aktif" />
                                    @else
                                        <form method="POST" action="{{ route('admin-utama.kontrol-sistem.akun', $u) }}"
                                              @if ($u->aktif)
                                                  data-konfirmasi="{{ $u->nama }} langsung keluar dan tidak bisa masuk sampai akunnya dinyalakan lagi."
                                                  data-konfirmasi-judul="Matikan akun {{ $u->nama }}?"
                                                  data-konfirmasi-tombol="Ya, Matikan"
                                              @endif>
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="aktif" value="{{ $u->aktif ? 0 : 1 }}">
                                            <button type="submit" class="sakelar" role="switch"
                                                    aria-checked="{{ $u->aktif ? 'true' : 'false' }}"
                                                    aria-label="Akun {{ $u->nama }}">
                                                <span class="sakelar-teks" aria-hidden="true">{{ $u->aktif ? 'Aktif' : 'Mati' }}</span>
                                                <span class="sakelar-jalur" aria-hidden="true"></span>
                                            </button>
                                        </form>
                                    @endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </section>

        <section class="panel kontrol-akses atur-penuh" id="akses" aria-labelledby="judul-akses">
            <div class="panel-head">
                <h2 id="judul-akses"><x-ikon-judul nama="kunci" />Hak Akses</h2>
            </div>

            @foreach ($akses as $potongan => $peran)
                <div class="kontrol-subjudul kontrol-subjudul-aksi">
                    <h3>{{ $peran['label'] }}</h3>
                    <a class="btn btn-sm" href="{{ route('admin-utama.kontrol-sistem.akses', $potongan) }}" data-popup>
                        Atur<span class="tersembunyi"> Hak Akses {{ $peran['label'] }}</span>
                    </a>
                </div>
                @php
                    $menuUtama = array_filter($peran['menu'], fn ($m) => $m['grup'] !== null);
                    $izinUtama = array_filter($menuUtama, fn ($m) => $m['aksi'][\App\Support\DaftarKontrol::AKSI_LIHAT]['boleh']);
                @endphp
                <ul class="daftar-ringkas daftar-akses">
                    @foreach (array_filter($peran['menu'], fn ($m) => $m['grup'] === null) as $m)
                        <li>
                            <div class="daftar-ringkas-isi">
                                <span class="daftar-ringkas-kiri">
                                    <span class="nama-barang">{{ $m['label'] }}</span>
                                </span>
                                <span class="daftar-ringkas-kanan">
                                    <span class="akses-ringkas">
                                        @foreach ($m['aksi'] as $aksi => $a)
                                            @continue(! $a['ada'])
                                            @include('admin-utama.kontrol-sistem._akses-badge', ['aksi' => $aksi, 'a' => $a])
                                        @endforeach
                                    </span>
                                </span>
                            </div>
                        </li>
                    @endforeach
                    @if ($menuUtama !== [])
                        <li>
                            <div class="daftar-ringkas-isi">
                                <span class="daftar-ringkas-kiri">
                                    <span class="nama-barang">{{ \App\Support\DaftarKontrol::GRUP_UTAMA }}</span>
                                    <span class="satuan">{{ $izinUtama === [] ? 'Belum ada yang diizinkan' : implode(', ', array_column($izinUtama, 'label')) }}</span>
                                </span>
                                <span class="daftar-ringkas-kanan">
                                    <span class="badge {{ $izinUtama === [] ? 'b-pasif' : 'b-aktif' }}">{{ count($izinUtama) }} dari {{ count($menuUtama) }}</span>
                                </span>
                            </div>
                        </li>
                    @endif
                </ul>
            @endforeach

            <p class="panel-kaki">Yang bergembok adalah mata rantai stok dan tidak bisa dimatikan.</p>
        </section>

        <section class="panel kontrol-periode" id="periode" aria-labelledby="judul-periode">
            <div class="panel-head">
                <div>
                    <h2 id="judul-periode"><x-ikon-judul nama="kalender" />Periode Buku</h2>
                    <div class="sub">
                        Periode berjalan {{ $periodeMulai ? 'sejak '.$periodeMulai->translatedFormat('j M Y') : 'belum ada transaksi' }}
                    </div>
                </div>
            </div>

            <div class="kontrol-isi">
                <p>Tutup buku tiap akhir bulan atau akhir tahun supaya sistem tetap ringan dan laporan mulai dari nol.</p>
                <p class="satuan">Semua transaksi disimpan ke Excel dan cadangan lalu dikosongkan. Bahan, menu, lokasi, dan akun tetap. Stok terakhir menjadi stok awal periode baru.</p>
            </div>

            <ul class="daftar-ringkas daftar-periode">
                @forelse ($periodeArsip as $p)
                    <li>
                        <div class="daftar-ringkas-isi">
                            <span class="daftar-ringkas-kiri">
                                <span class="nama-barang">{{ $p->nama }}</span>
                                <span class="satuan">{{ $p->rentang() }}</span>
                            </span>
                            <span class="daftar-ringkas-kanan periode-unduh">
                                <a class="link" href="{{ route('admin-utama.kontrol-sistem.periode.unduh', [$p, 'excel']) }}" download>Excel</a>
                                <a class="link" href="{{ route('admin-utama.kontrol-sistem.periode.unduh', [$p, 'cadangan']) }}" download>Cadangan</a>
                            </span>
                        </div>
                    </li>
                @empty
                    <li><x-kosong gambar="kotak" kecil>Belum pernah tutup buku.</x-kosong></li>
                @endforelse
            </ul>

            <div class="simpan-bar">
                @if ($periodeSiap)
                    <div class="simpan-aksi">
                        <a class="btn" href="{{ route('admin-utama.kontrol-sistem.periode') }}" data-popup>
                            <x-ikon nama="kalender" />
                            Mulai Periode Baru…
                        </a>
                    </div>
                @else
                    <p class="satuan">{{ \App\Services\PeriodeBukuService::PESAN_BELUM_TERPASANG }}</p>
                @endif
            </div>
        </section>
    </div>
@endsection
