{{--
    Lingkaran pengguna: foto profil bila ada, selain itu inisial nama dengan warna sesuai peran.
    Prop: pengguna (User).
--}}
@props(['pengguna'])

@php
    $warna = [
        \App\Models\User::ROLE_ADMIN_PUSAT  => 'ava-pusat',
        \App\Models\User::ROLE_ADMIN_CABANG => 'ava-cabang',
    ][$pengguna->role] ?? '';
    $src = $pengguna->urlFoto();
@endphp

<span {{ $attributes->class(['ava', $warna, 'ava-foto' => $src]) }} aria-hidden="true">
    @if ($src)
        <img src="{{ $src }}" alt="" decoding="async">
    @else
        {{ $pengguna->inisial }}
    @endif
</span>
