@if($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Paginação">
        <p class="pagination__summary">
            Exibindo <strong>{{ $paginator->firstItem() }}</strong> a <strong>{{ $paginator->lastItem() }}</strong>
            de <strong>{{ $paginator->total() }}</strong> resultados
        </p>

        <div class="pagination__controls">
            @if($paginator->onFirstPage())
                <span class="pagination__button is-disabled" aria-disabled="true">Anterior</span>
            @else
                <a class="pagination__button" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
            @endif

            @foreach($elements as $element)
                @if(is_string($element))
                    <span class="pagination__ellipsis" aria-hidden="true">{{ $element }}</span>
                @else
                    @foreach($element as $page => $url)
                        @if($page == $paginator->currentPage())
                            <span class="pagination__button is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pagination__button" href="{{ $url }}" aria-label="Ir para a página {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if($paginator->hasMorePages())
                <a class="pagination__button" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima</a>
            @else
                <span class="pagination__button is-disabled" aria-disabled="true">Próxima</span>
            @endif
        </div>
    </nav>
@endif
