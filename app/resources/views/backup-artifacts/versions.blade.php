@extends('layouts.app')
@section('title', 'Versões de configuração — Backup Manager')
@section('page-title', 'Versões de configuração')
@section('page-description', 'Histórico e diferenças entre configurações recebidas.')

@section('content')
<div class="backup-artifacts-page stack">
    <header class="page-header">
        <div class="page-header__content">
            <h1 class="page-header__title">Versões de {{ $backupArtifact->device?->name ?? 'equipamento' }}</h1>
            <p class="page-header__description">Capturas do mesmo equipamento e política. Recebimentos próximos são apresentados juntos após até 60 minutos de intervalo; os arquivos originais permanecem no histórico.@if($backupArtifact->type === 'binary') A comparação A10 usa o startup-config.pri salvo dentro do pacote.@endif</p>
        </div>
        <div class="page-header__actions"><a class="btn btn--secondary" href="{{ route('backup-artifacts.show', $backupArtifact) }}">Voltar ao artefato</a></div>
    </header>

    <section class="card">
        <div class="card__header"><div><h2 class="card__title">Histórico</h2><p class="card__description">Até 200 capturas recentes disponíveis. A última captura de cada grupo representa a versão.</p></div></div>
        <div class="card__body">
            <div class="table-shell" role="region" aria-label="Histórico de versões" tabindex="0">
                <table class="data-table">
                    <thead><tr><th>Recebido em</th><th>Capturas agrupadas</th><th>Conteúdo</th><th>Arquivo</th><th>Ação</th></tr></thead>
                    <tbody>
                    @foreach($versions as $version)
                        @php $older = $versions->get($loop->index + 1); @endphp
                        <tr>
                            <td>{{ app(\App\Services\InstanceTimezone::class)->format($version['last_at'], 'd/m/Y H:i:s') }}</td>
                            <td>{{ $version['captures'] }}</td>
                            <td>{{ $older && $older['fingerprint'] === $version['fingerprint'] ? 'Sem alteração' : ($version['text_available'] ? 'Versão de texto' : 'Comparação indisponível') }}</td>
                            <td><a class="link" href="{{ route('backup-artifacts.show', $version['artifact']) }}">Artifact #{{ $version['artifact']->id }}</a></td>
                            <td><a class="link" href="{{ route('backup-artifacts.versions', [$backupArtifact, 'version' => $version['artifact']->id]) }}">Comparar</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card__header"><div><h2 class="card__title">Comparação do artifact #{{ $selected['artifact']->id }}</h2><p class="card__description">Diferenças em relação à versão anterior da mesma política.</p></div></div>
        <div class="card__body">
            @if(!$previous)
                <p>Esta é a primeira versão disponível nesta consulta.</p>
            @elseif($difference === null)
                <p>Comparação textual indisponível para um dos arquivos. Os backups continuam disponíveis para download.</p>
            @elseif(!$difference['changed'])
                <p>O texto da configuração não mudou entre as duas versões.</p>
            @else
                <p>Linhas com <strong>−</strong> foram removidas; linhas com <strong>+</strong> foram adicionadas.</p>
                <pre class="text-technical" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:38rem;overflow:auto">{{ $difference['text'] }}</pre>
                @if($difference['truncated'] ?? false)<p>Comparação longa: exibidos os primeiros 200 KB.</p>@endif
            @endif
        </div>
    </section>
</div>
@endsection
