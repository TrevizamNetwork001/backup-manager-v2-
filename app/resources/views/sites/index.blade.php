@extends('layouts.app')

@section('title', 'Sites / POPs — Backup Manager')
@section('page-title', 'Sites / POPs')
@section('page-description', 'Organize os locais onde os equipamentos estão instalados.')

@section('content')

@if(session('success'))
    <div class="alert-success">
        {{ session('success') }}
    </div>
@endif

<div class="page-toolbar">
    <div>
        <strong>{{ $sites->total() }}</strong>
        <span>{{ $sites->total() === 1 ? 'local cadastrado' : 'locais cadastrados' }}</span>
    </div>

    <a href="{{ route('sites.create') }}" class="primary-button inline-button">
        + Novo Site / POP
    </a>
</div>

<article class="panel table-panel">

    @if($sites->isEmpty())

        <div class="empty-state">
            <div class="empty-icon">◎</div>

            <h3>Nenhum Site / POP cadastrado</h3>

            <p>
                Cadastre a primeira localização para começar a organizar
                os equipamentos da infraestrutura.
            </p>

            <a href="{{ route('sites.create') }}" class="primary-button empty-action">
                Cadastrar primeiro Site / POP
            </a>
        </div>

    @else

        <div class="table-responsive">

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
                        <tr>
                            <td>
                                <strong>{{ $site->name }}</strong>

                                @if($site->description)
                                    <small>{{ \Illuminate\Support\Str::limit($site->description, 70) }}</small>
                                @endif
                            </td>

                            <td>
                                {{ $site->code ?: '—' }}
                            </td>

                            <td>
                                {{ $site->location ?: '—' }}
                            </td>

                            <td>
                                @if($site->is_active)
                                    <span class="badge success">Ativo</span>
                                @else
                                    <span class="badge neutral">Inativo</span>
                                @endif
                            </td>

                            <td class="table-actions">
                                <a
                                    href="{{ route('sites.edit', $site) }}"
                                    class="table-action"
                                >
                                    Editar
                                </a>

                                <form
                                    method="POST"
                                    action="{{ route('sites.destroy', $site) }}"
                                    onsubmit="return confirm('Remover este Site / POP?');"
                                >
                                    @csrf
                                    @method('DELETE')

                                    <button type="submit" class="table-action danger-text">
                                        Remover
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

        </div>

        @if($sites->hasPages())
            <div class="pagination-container">
                {{ $sites->links() }}
            </div>
        @endif

    @endif

</article>

@endsection
