@extends('layouts.app')

@section('title', 'Sites / POPs — Backup Manager')
@section('page-title', 'Sites / POPs')
@section('page-description', 'Organize os locais onde os equipamentos estão instalados.')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Sites / POPs</h1>
        <p class="page-header__description">Organize os locais onde os equipamentos estão instalados.</p>
    </div>
    <div class="page-header__actions">
        @can('sites.manage')
            <button type="button" class="btn btn--primary" data-open-site-create><x-icon name="add" size="sm" /> Novo Site / POP</button>
        @endcan
    </div>
</header>
@endsection

@section('content')

<div class="sites-list stack">

@if(session('success'))
    <div class="alert alert--success" role="status">
        {{ session('success') }}
    </div>
@endif

<div class="toolbar">
    <div class="toolbar__primary">
        <span class="toolbar__count"><strong>{{ $sites->total() }}</strong> {{ $sites->total() === 1 ? 'local cadastrado' : 'locais cadastrados' }}</span>
    </div>
    @unless($sites->isEmpty())
        <label class="list-search">Buscar nesta página <input class="form-control" type="search" data-list-search="sites-rows" placeholder="Nome, código ou localização"></label>
    @endunless
</div>

    @if($sites->isEmpty())

        <div class="empty-state">
            <div class="empty-state__icon" aria-hidden="true"><x-icon name="device" /></div>

            <h2 class="empty-state__title">Nenhum Site / POP cadastrado</h2>

            <p class="empty-state__description">
                Cadastre a primeira localização para começar a organizar
                os equipamentos da infraestrutura.
            </p>

            @can('sites.manage')
            <div class="empty-state__actions"><button type="button" class="btn btn--secondary" data-open-site-create>Cadastrar primeiro Site / POP</button></div>
            @endcan
        </div>

    @else

        <div class="table-shell" role="region" aria-label="Lista de Sites e POPs" tabindex="0">

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Código</th>
                        <th>Localização</th>
                        <th>Status</th>
                        <th class="table-actions-column">Ações</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($sites as $site)
                        <tr data-list-row="sites-rows" data-search="{{ mb_strtolower($site->name.' '.$site->code.' '.$site->location.' '.$site->description) }}">
                            <td data-label="Nome">
                                <div class="entity-cell">
                                    <span class="entity-cell__title">{{ $site->name }}</span>
                                    @if($site->description)
                                        <span class="entity-cell__meta">{{ \Illuminate\Support\Str::limit($site->description, 70) }}</span>
                                    @endif
                                </div>
                            </td>

                            <td data-label="Código">
                                @if($site->code)<span class="tech-value">{{ $site->code }}</span>@else<span class="table-muted">—</span>@endif
                            </td>

                            <td data-label="Localização">
                                {{ $site->location ?: '—' }}
                            </td>

                            <td data-label="Status">
                                @if($site->is_active)
                                    <span class="badge badge--success">Ativo</span>
                                @else
                                    <span class="badge badge--neutral">Inativo</span>
                                @endif
                            </td>

                            <td data-label="Ações">
                                @canany(['sites.manage', 'sites.delete'])
                                <details class="row-menu"><summary>Ações</summary><div class="table-actions">
                                    @can('sites.manage')
                                    <a href="{{ route('sites.edit', $site) }}" class="btn btn--ghost btn--sm">Editar</a>
                                    @endcan
                                    @can('sites.delete')
                                    <form
                                        method="POST"
                                        action="{{ route('sites.destroy', $site) }}"
                                        onsubmit="return confirm('Remover este Site / POP? Só é possível se não houver equipamentos vinculados.');"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button type="submit" class="btn btn--ghost btn--sm table-actions__danger">Remover</button>
                                    </form>
                                    @endcan
                                </div></details>
                                @endcanany
                            </td>
                        </tr>
                    @endforeach
                    <tr data-list-empty="sites-rows" hidden><td colspan="5" class="empty-table">Nenhum site nesta página corresponde à busca.</td></tr>
                </tbody>
            </table>

        </div>

        @if($sites->hasPages())
            <div class="pagination-container">
                {{ $sites->links() }}
            </div>
        @endif

    @endif

</div>
@can('sites.manage')
<dialog class="modal form-create-modal" id="site-create-dialog" aria-labelledby="site-create-title">
    <div class="modal__surface">
        <div class="modal__header">
            <div><h2 class="modal__title" id="site-create-title">Novo Site / POP</h2><p class="modal__description">Cadastre uma localização lógica ou física da infraestrutura.</p></div>
            <button type="button" class="modal__close" data-close-site-create aria-label="Fechar"><x-icon name="close" /></button>
        </div>
        <form method="POST" action="{{ route('sites.store') }}">
            @include('sites._form', ['createModal' => true])
        </form>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('site-create-dialog');
    document.querySelectorAll('[data-open-site-create]').forEach(button => button.addEventListener('click', () => dialog.showModal()));
    dialog.querySelectorAll('[data-close-site-create]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    @if ($errors->any()) dialog.showModal(); @endif
})();
</script>
@endcan
@endsection
