{{-- Navigasi halaman di kaki panel daftar. Prop: daftar (paginator). --}}
@props(['daftar'])

@if ($daftar->hasPages())
    <div class="panel-kaki">
        {{ $daftar->links('vendor.pagination.stokku') }}
    </div>
@endif