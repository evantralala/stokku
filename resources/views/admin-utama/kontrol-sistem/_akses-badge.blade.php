{{-- Status satu aksi hak akses. Variabel: $aksi (lihat/catat), $a (['ada', 'kunci', 'boleh']). --}}
@php $label = \App\Support\DaftarKontrol::LABEL_AKSI[$aksi]; @endphp
<span class="badge {{ $a['boleh'] ? 'b-aktif' : 'b-pasif' }}"
      title="{{ $label }}: {{ $a['kunci'] ? 'selalu boleh (dikunci)' : ($a['boleh'] ? 'boleh' : 'tidak boleh') }}">
    @if ($a['kunci'])
        <x-ikon nama="kunci" width="12" height="12" />
    @endif
    {{ $label }}{{ $a['boleh'] ? '' : ' mati' }}
</span>
