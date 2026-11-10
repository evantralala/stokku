{{-- Pagination dengan gaya komponen.css (.paging, .hal). --}}
@if ($paginator->hasPages())
    <nav class="paging" aria-label="Halaman">
        @if ($paginator->onFirstPage())
            <span class="hal mati" aria-hidden="true">&lsaquo;</span>
        @else
            <a class="hal" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya">&lsaquo;</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="hal mati">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="hal on" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="hal" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="hal" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Berikutnya">&rsaquo;</a>
        @else
            <span class="hal mati" aria-hidden="true">&rsaquo;</span>
        @endif

        <span class="paging-info">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }}</span>
    </nav>
@endif
