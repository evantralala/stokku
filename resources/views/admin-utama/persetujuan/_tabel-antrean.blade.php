{{--
    Tabel antrean persetujuan. Variabel: $daftar (baris AntreanPersetujuanService), $pilihanJenis,
    $ringkas (opsional, untuk kolom sempit seperti dashboard: lokasi dan batas digabung di bawah nomor),
    $pilih (opsional, id x-pilih.bilah: dokumen wajar bisa dicentang lalu disetujui sekaligus).
--}}
@php $ringkas ??= false; $pilih ??= null; @endphp
@if ($pilih)
    <x-pilih.semua-hp :form="$pilih" label="Pilih Semua Wajar" />
@endif
<div class="tabel-bungkus">
    <table class="tabel-kartu tabel-dokumen tabel-antrean">
        <thead>
            <tr>
                <th>Dokumen</th>
                @unless ($ringkas)
                    <th>Lokasi</th>
                    <th>Putuskan Sebelum</th>
                @endunless
                <th>Cek</th>
                <th><span class="tersembunyi">Aksi</span></th>
                @if ($pilih)
                    <th class="ka kolom-pilih"><x-pilih.semua :form="$pilih" label="semua dokumen wajar" /></th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($daftar as $a)
                @include('admin-utama.persetujuan._antrean')
            @endforeach
        </tbody>
    </table>
</div>
