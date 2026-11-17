@if ($paginator->hasPages())
    <div class="pagination">
        @if ($paginator->onFirstPage())
            <span class="soft small">{{ __('Précédent') }}</span>
        @else
            <a class="btn ghost small" href="{{ $paginator->previousPageUrl() }}">{{ __('Précédent') }}</a>
        @endif
        <span class="soft small">{{ __('Page :v1', ['v1' => $paginator->currentPage().' / '.$paginator->lastPage()]) }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn ghost small" href="{{ $paginator->nextPageUrl() }}">{{ __('Suivant') }}</a>
        @else
            <span class="soft small">{{ __('Suivant') }}</span>
        @endif
    </div>
@endif
