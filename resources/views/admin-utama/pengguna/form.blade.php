{{-- Form tambah / ubah pengguna. Variabel dari PenggunaController::dataForm(). --}}
@extends('layouts.app')

@section('crumb')
    <a class="link" href="{{ route($rute) }}">Pengguna</a>
@endsection
@section('judul', $item ? 'Ubah Pengguna' : 'Tambah Pengguna')
@section('sub', $item?->nama ?? '')

@section('content')
    <div class="isi-master" data-popup-isi data-popup-ukuran="panjang">
        <form class="panel" method="POST"
              action="{{ $item ? route($rute.'.perbarui', $item) : route($rute.'.simpan') }}" novalidate>
            @csrf
            @if ($item)
                @method('PUT')
            @endif

            <div class="form-grid">
                <x-form.teks name="nama" label="Nama Lengkap" :value="$item?->nama" wajib
                             :maxlength="$maks['nama']" autocomplete="off" placeholder="Contoh: Fitri Nuraini" />

                <x-form.teks name="email" label="Email" type="email" :value="$item?->email" wajib
                             :maxlength="$maks['email']" inputmode="email" autocapitalize="none" spellcheck="false"
                             autocomplete="off" placeholder="nama@contoh.com" />

                @if ($akunSendiri)
                    <x-form.tetap label="Peran" :nilai="$item->label_role" name="role" :value="$item->role"
                                  bantu="Peran akun sendiri tidak bisa diubah." />
                @else
                    <x-form.pilih-kotak name="role" label="Peran" :pilihan="$pilihanPeran" :kolom="count($pilihanPeran)"
                                        :value="$item?->role" wajib
                                        bantu="Admin Cabang hanya melihat cabangnya sendiri." />
                @endif

                <div class="field" data-tampil-jika="role:{{ \App\Models\User::ROLE_ADMIN_PUSAT }}">
                    <span class="label-tetap">Lokasi</span>
                    @if ($gudangPusat)
                        <div class="nilai-tetap">{{ $gudangPusat->nama }}</div>
                        <span class="bantu">Admin Pusat otomatis bertugas di Gudang Pusat.</span>
                    @else
                        <div class="nilai-tetap">Belum ada Gudang Pusat aktif</div>
                        <span class="bantu"><a class="link" href="{{ route('admin-utama.lokasi.tambah') }}" data-popup>Tambahkan Gudang Pusat</a> terlebih dahulu.</span>
                    @endif
                </div>

                <div data-tampil-jika="role:{{ \App\Models\User::ROLE_ADMIN_CABANG }}">
                    <x-form.pilihan name="cabang_id" label="Cabang" :pilihan="$pilihanCabang"
                                    :value="$item?->isAdminCabang() ? $item->cabang_id : null" kosong="Pilih Cabang" wajib
                                    :bantu="$pilihanCabang ? null : 'Belum ada cabang aktif. Tambahkan di menu Lokasi.'" />
                </div>

                @unless ($item)
                    <x-form.teks name="password" label="Password" type="password" wajib
                                 autocomplete="new-password" bantu="Minimal 8 karakter." />
                    <x-form.teks name="password_confirmation" label="Ulangi Password" type="password" wajib
                                 autocomplete="new-password" />
                @endunless
            </div>

            <x-form.simpan :batal="route($rute)"
                           :label="$item ? 'Simpan Perubahan' : 'Simpan Pengguna'"
                           :tambah-lagi="! $item" />
        </form>

        @if ($item && ! $akunSendiri)
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Atur Ulang Password</h2>
                        <div class="sub">Dipakai bila {{ $item->nama }} lupa password. Ia akan keluar dari semua perangkat.</div>
                    </div>
                </div>

                <form method="POST" action="{{ route($rute.'.password', $item) }}" novalidate
                      data-konfirmasi="{{ $item->nama }} harus masuk lagi dengan password baru."
                      data-konfirmasi-judul="Ganti Password?"
                      data-konfirmasi-tombol="Ya, Ganti">
                    @csrf
                    @method('PATCH')

                    <div class="form-grid">
                        <x-form.teks name="password_baru" label="Password Baru" type="password" wajib
                                     autocomplete="new-password" bantu="Minimal 8 karakter." />
                        <x-form.teks name="password_baru_confirmation" label="Ulangi Password" type="password" wajib
                                     autocomplete="new-password" />
                    </div>

                    <div class="simpan-bar">
                        <div class="simpan-aksi">
                            <button type="submit" class="btn">Ganti Password</button>
                        </div>
                    </div>
                </form>
            </section>
        @elseif ($akunSendiri)
            <div class="kilat kilat-info" role="status">
                <x-ikon nama="kunci" />
                <p>Ini akun Anda. Ganti password lewat halaman <a class="link" href="{{ route('akun') }}">Akun saya</a>.</p>
            </div>
        @endif

        @if ($item && ! $akunSendiri)
            <x-master.kelola :rute="$rute" :item="$item" label="pengguna" :bisa-dihapus="$bisaDihapus"
                             dampak="tidak bisa masuk ke sistem" />
        @endif
    </div>
@endsection
