{{--
    Keputusan Admin Utama atas satu stock opname. Variabel dari PersetujuanController@opname:
      $opname, $detail (urut: di luar toleransi lebih dulu), $ringkasan, $pilihanTolak, $maksCatatan
    Kiri: ringkasan dan tabel selisih (bahan yang sesuai dilipat). Kanan: kotak keputusan, riwayat keputusan. Atas: kartu kepala (nomor, status, info). Bawah kiri: kotak dampak.
--}}
@extends('layouts.app')

@php
    $aksi = \App\Services\PersetujuanService::class;
    $keBeli = fn ($b, $qty) => \App\Support\Angka::tampil($b->keSatuanBeli($qty)).' '.$b->satuanBeli->nama;
    [$label, $kelas] = $opname->tampilanStatus();
    [$berselisih, $cocok] = $detail->partition(fn ($d) => (float) $d->qty_fisik !== (float) $d->qty_sistem);
@endphp

@section('crumb')
    <a class="link" href="{{ route('admin-utama.persetujuan') }}">Persetujuan</a>
@endsection
@section('judul', 'Opname '.$opname->cabang->nama)
@section('sub', $opname->nomor.' · '.$opname->tanggal->translatedFormat('j M Y'))

@section('content')
    <x-dokumen.kepala ikon="ceklis" nada="netral" jenis="Stock Opname" :nomor="$opname->nomor" :ket="$opname->created_at ? 'dibuat '.$opname->created_at->translatedFormat('d/m/Y, H.i') : null" :info="[
        'Lokasi'  => $opname->cabang->nama,
        'Tanggal' => $opname->tanggal->translatedFormat('j M Y'),
        'Petugas' => $opname->petugas->nama,
        'Bahan'   => $detail->count().' bahan dihitung',
    ]">
        <x-slot:status>
            <span class="badge {{ $kelas }}">{{ $label }}</span>
        </x-slot:status>
    </x-dokumen.kepala>

    <x-dokumen.tata>
        <section class="panel">
            <div class="ringkasan-opname">
                <div><span class="angka">{{ $ringkasan['sesuai'] }}</span> sesuai</div>
                <div><span class="angka">{{ $ringkasan['dalam'] }}</span> selisih wajar</div>
                <div class="{{ $ringkasan['luar'] > 0 ? 'teks-kurang' : '' }}"><span class="angka">{{ $ringkasan['luar'] }}</span> lewat toleransi</div>
            </div>

            @if ($berselisih->isNotEmpty())
                <div class="tabel-bungkus">
                    <table class="tabel-kartu tabel-ringkas tabel-barang">
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th class="ka">Sistem</th>
                                <th class="ka">Fisik</th>
                                <th class="ka">Selisih</th>
                                <th>Toleransi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($berselisih as $d)
                                @include('admin-utama.persetujuan._baris-opname', ['d' => $d])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($cocok->isNotEmpty())
                <details class="opname-cocok">
                    <summary>{{ $cocok->count() }} bahan sesuai</summary>
                    <div class="tabel-bungkus">
                        <table class="tabel-kartu tabel-ringkas tabel-barang">
                            <thead>
                                <tr>
                                    <th>Bahan</th>
                                    <th class="ka">Sistem</th>
                                    <th class="ka">Fisik</th>
                                    <th class="ka">Selisih</th>
                                    <th>Toleransi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($cocok as $d)
                                    @include('admin-utama.persetujuan._baris-opname', ['d' => $d])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </section>

        <x-dokumen.dampak>Setelah disetujui Admin Utama, stok disamakan dengan hasil hitung fisik; selisihnya tercatat di kartu stok sebagai koreksi stock opname.</x-dokumen.dampak>

        <x-slot:samping>
            @if ($opname->status === \App\Models\Opname::STATUS_DIKIRIM)
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan</h2></div>
                    <form class="kotak-tindakan-isi" method="POST" action="{{ route('admin-utama.persetujuan.opname.putuskan', $opname) }}"
                          data-konfirmasi="Stok sistem {{ $opname->cabang->nama }} akan disamakan dengan hasil hitung fisik, dan kunci stok dibuka."
                          data-konfirmasi-judul="Setujui Hasil Opname?"
                          data-konfirmasi-tombol="Ya, Setujui">
                        @csrf
                        <input type="hidden" name="aksi" value="{{ $aksi::AKSI_SETUJUI }}">
                        <p>Selisihnya bisa diterima?</p>
                        <button type="submit" class="btn btn-merah">
                            <x-ikon nama="ceklis" />
                            Setujui
                        </button>
                    </form>

                    <details class="lipat-tolak"{{ $errors->any() ? ' open' : '' }}>
                        <summary>Hitung Ulang atau Tolak</summary>
                        <form method="POST" action="{{ route('admin-utama.persetujuan.opname.putuskan', $opname) }}" novalidate
                              data-konfirmasi="Stok sistem tidak dikoreksi. Petugas akan melihat catatan Anda."
                              data-konfirmasi-judul="Kirim Keputusan?"
                              data-konfirmasi-tombol="Ya, Kirim">
                            @csrf
                            <div class="form-grid">
                                <x-form.pilihan name="aksi" label="Tindakan" :pilihan="$pilihanTolak" :value="$aksi::AKSI_REVISI" wajib />
                                <x-form.teks name="catatan" label="Alasan" wajib lebar :maxlength="$maksCatatan" autocomplete="off"
                                             placeholder="Mis. hitung ulang nori dan mie, selisihnya terlalu besar" />
                            </div>
                            <div class="simpan-bar">
                                <div class="simpan-aksi">
                                    <button type="submit" class="btn btn-bahaya">Kirim Keputusan</button>
                                </div>
                            </div>
                        </form>
                    </details>
                </section>
            @endif

            @if ($opname->persetujuan->isNotEmpty())
                <section class="panel kotak-tindakan">
                    <div class="panel-head"><h2>Keputusan Sebelumnya</h2></div>
                    <x-riwayat-keputusan :riwayat="$opname->persetujuan" />
                </section>
            @endif

            <x-dokumen.berkas :daftar="[[
                'judul' => 'Hasil stock opname',
                'ket'   => 'Hitung fisik, stok sistem, dan selisih',
                'url'   => route('admin-utama.persetujuan.opname.pdf', $opname),
            ], [
                'judul' => 'Hasil stock opname',
                'ket'   => 'Excel',
                'url'   => route('admin-utama.persetujuan.opname.excel', $opname),
                'jenis' => 'XLS',
            ]]" />

        </x-slot:samping>
    </x-dokumen.tata>
@endsection
