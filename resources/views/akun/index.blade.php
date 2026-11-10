{{--
    Akun pengguna yang sedang masuk. Butuh $pengguna dari LayoutComposer.
    Dari AkunController@index: $email (EmailLaporan), $pilihanLaporan ([kunci => label] sesuai peran), $jamKirim.
--}}
@extends('layouts.app')

@section('judul', 'Akun')
@section('sub', $pengguna->email)

@section('content')
    @php
        $warnaAva = [
            \App\Models\User::ROLE_ADMIN_PUSAT  => 'ava-pusat',
            \App\Models\User::ROLE_ADMIN_CABANG => 'ava-cabang',
        ][$pengguna->role] ?? '';
    @endphp

    <section class="panel profil-akun">
        <div class="profil-akun-atas">
            <span class="ava ava-besar {{ $warnaAva }}" aria-hidden="true">{{ $pengguna->inisial }}</span>
            <div class="profil-akun-teks">
                <h2>{{ $pengguna->nama }}</h2>
                <div class="sub">{{ $pengguna->label_role }} · {{ $pengguna->cabang?->nama ?? 'Semua Lokasi' }}</div>
            </div>
            <x-master.status :aktif="$pengguna->aktif" />
        </div>
        <dl class="kepala-dokumen-info">
            <div><dt>Email</dt><dd>{{ $pengguna->email }}</dd></div>
            <div><dt>Peran</dt><dd>{{ $pengguna->label_role }}</dd></div>
            <div><dt>Lokasi</dt><dd>{{ $pengguna->cabang?->nama ?? 'Semua Lokasi' }}</dd></div>
        </dl>
        <p class="panduan">Data akun diatur Admin Utama.</p>
    </section>

    <section class="panel pasang-aplikasi" aria-labelledby="judul-pasang">
        <div class="panel-head">
            <div>
                <h2 id="judul-pasang"><x-ikon-judul nama="rumah" />Pasang di HP</h2>
                <div class="sub">{{ \App\Support\Identitas::aplikasi() }} bisa dipasang seperti aplikasi: ada ikon di layar utama dan terbuka layar penuh.</div>
            </div>
        </div>
        <div class="pasang-isi">
            <p class="pasang-status" data-aplikasi-terpasang hidden>Aplikasi sudah terpasang dan sedang dibuka dari layar utama.</p>
            <ul class="pasang-langkah">
                <li><strong>Android (Chrome):</strong> tekan tombol <strong>Pasang aplikasi</strong> bila muncul, atau menu <strong>⋮</strong> lalu <strong>Instal aplikasi</strong> / <strong>Tambahkan ke layar utama</strong>.</li>
                <li data-pasang-ios hidden><strong>iPhone / iPad (Safari):</strong> tekan <strong>Bagikan</strong> lalu <strong>Tambah ke Layar Utama</strong>.</li>
                <li><strong>Laptop (Chrome / Edge):</strong> tekan ikon pasang di ujung kanan kolom alamat.</li>
            </ul>
        </div>
        <div class="simpan-bar" data-pasang-aplikasi hidden>
            <div class="simpan-aksi">
                <button type="button" class="btn btn-merah">
                    <x-ikon nama="masuk" />
                    Pasang Aplikasi
                </button>
            </div>
        </div>
    </section>

    @php
        $gagalEmail = $errors->hasAny(['aktif', 'alamat_teks', 'laporan', 'laporan.0']);
        $aktifEmail = $gagalEmail ? (bool) old('aktif') : $email->aktif;
        $laporanDipilih = $gagalEmail ? (array) old('laporan', []) : (array) $email->laporan;
        $alamatTeks = implode("\n", (array) $email->alamat);
        $bantuAlamat = 'Satu email per baris, maksimal '.\App\Support\DaftarLaporanEmail::MAKS_ALAMAT.'.';
    @endphp

    <form id="email-laporan" class="panel" method="POST" action="{{ route('akun.email') }}" novalidate>
        @csrf
        @method('PUT')

        <div class="panel-head">
            <div>
                <h2><x-ikon-judul nama="lonceng" />Email Laporan Harian</h2>
                <div class="sub">Dikirim otomatis setiap hari pukul {{ $jamKirim }}: ringkasan di email, rincian di file Excel.</div>
            </div>
        </div>

        <div class="form-grid">
            <label class="centang-baris lebar">
                <input type="checkbox" name="aktif" value="1" @checked($aktifEmail)>
                <span>Kirim otomatis</span>
            </label>

            <x-form.area name="alamat_teks" label="Email Tujuan" :value="$alamatTeks" :bantu="$bantuAlamat" autocomplete="off" />

            <fieldset class="kelompok-centang lebar">
                <legend>Laporan</legend>
                @foreach ($pilihanLaporan as $kunci => $label)
                    <label class="centang-baris">
                        <input type="checkbox" name="laporan[]" value="{{ $kunci }}" @checked(in_array($kunci, $laporanDipilih, true))>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                @error('laporan') <p class="pesan-galat" role="alert">{{ $message }}</p> @enderror
            </fieldset>

            @if ($email->terakhir_dikirim)
                <p class="satuan lebar">Terakhir terkirim: laporan {{ $email->terakhir_dikirim->translatedFormat('j F Y') }}.</p>
            @endif
        </div>

        <div class="simpan-bar">
            <div class="simpan-aksi">
                @if ($email->exists)
                    <button type="submit" class="btn" form="kirim-email-laporan">Kirim Sekarang</button>
                @endif
                <button type="submit" class="btn btn-merah">
                    <x-ikon nama="ceklis" />
                    Simpan
                </button>
            </div>
        </div>
    </form>

    @if ($email->exists)
        <form id="kirim-email-laporan" method="POST" action="{{ route('akun.email.kirim') }}" hidden
              data-konfirmasi="Laporan hari ini dikirim sekarang ke {{ implode(', ', (array) $email->alamat) }}."
              data-konfirmasi-judul="Kirim Laporan Sekarang?"
              data-konfirmasi-tombol="Ya, Kirim">
            @csrf
        </form>
    @endif

    <form class="panel" method="POST" action="{{ route('akun.password') }}" novalidate>
        @csrf
        @method('PUT')

        <div class="panel-head">
            <div>
                <h2>Ganti Password</h2>
                <div class="sub">Perangkat lain yang memakai akun ini akan dikeluarkan.</div>
            </div>
        </div>

        <div class="form-grid">
            <x-form.teks name="password_lama" label="Password Lama" type="password" wajib lebar
                         autocomplete="current-password" />
            <x-form.teks name="password_baru" label="Password Baru" type="password" wajib
                         autocomplete="new-password" bantu="Minimal 8 karakter." />
            <x-form.teks name="password_baru_confirmation" label="Ulangi Password" type="password" wajib
                         autocomplete="new-password" />
        </div>

        <x-form.simpan :batal="route($pengguna->ruteDashboard())" label="Ganti Password" />
    </form>
@endsection
