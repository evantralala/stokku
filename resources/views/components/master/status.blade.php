{{-- Badge status aktif / tidak aktif. Prop: aktif (bool). --}}
@props(['aktif'])

<span {{ $attributes->class(['badge', 'b-aktif' => $aktif, 'b-pasif' => ! $aktif]) }}>
    {{ $aktif ? 'Aktif' : 'Tidak Aktif' }}
</span>